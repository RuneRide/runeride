#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# Prépare l'état serveur de l'application sur o2switch (cPanel), en deux phases.
#
# Phase 1 (avant le premier déploiement) :
#   - base MariaDB et utilisateur dédiés, droits complets sur cette base seule
#   - dossier ~/app/storage et fichier ~/app/.env (jamais déployé par la CI), mot de passe et clé GeoRide aléatoires
#   - version de PHP du sous-domaine (>= 8.3)
# Phase 2 (--migrate, après le premier déploiement) :
#   - tâche cron éphémère qui exécute bin/cle-jwt (clé RS256, créée sur le serveur, elle n'en sort jamais)
#     puis bin/migrer ; la tâche est supprimée dès qu'elle a répondu
#
# Le jeton cPanel et les secrets générés ne sont jamais affichés, écrits sur disque ni passés en argument.
#
# Usage :
#   AWS_PROFILE=bike360 ops/app-provision.sh              # simulation de la phase 1
#   AWS_PROFILE=bike360 ops/app-provision.sh --apply      # phase 1
#   AWS_PROFILE=bike360 ops/app-provision.sh --migrate    # phase 2 (le code doit être déployé)
#   option : --force-env  recrée .env et change le mot de passe de la base (les secrets existants sont perdus)
#
# Prérequis : aws (accès à Secrets Manager), curl, jq, openssl, awk.
set -euo pipefail

SECRET_ID="runeride/cpanel"; REGION="eu-north-1"; ROOT_DOMAIN="runeride.fr"; FQDN="app.runeride.fr"
APP_DIR="app"; DB_SUFFIX="runeride"; DBUSER_SUFFIX="rride"

APPLY=0; MIGRATE=0; FORCE_ENV=0
for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=1 ;;
    --migrate) MIGRATE=1 ;;
    --force-env) FORCE_ENV=1 ;;
    *) echo "Option inconnue : $arg" >&2; exit 2 ;;
  esac
done

secret=$(aws secretsmanager get-secret-value --region "$REGION" --secret-id "$SECRET_ID" --query SecretString --output text)
pick() { jq -r --arg keys "$1" '[ ($keys | split(",")[]) as $k | .[$k]? | select(. != null and . != "") ] | first // empty' <<<"$secret"; }
host=$(pick host,hostname,server,url,cpanel_host); user=$(pick user,username,login,cpanel_user); token=$(pick token,api_token,apitoken,key,secret)
host=${host#*://}; host=${host%%/*}; host=${host%%:*}
[[ -n "$host" && -n "$user" && -n "$token" ]] || { echo "Secret inattendu (hôte, utilisateur ou jeton manquant)." >&2; exit 1; }
unset secret

curl_call() {  # $1 = URL ; puis paires clé=valeur ; jeton et valeurs passent par stdin
  local url="$1"; shift
  { printf 'header = "Authorization: cpanel %s:%s"\n' "$user" "$token"; printf 'url = "%s"\n' "$url"; printf 'get\n'
    for kv in "$@"; do printf 'data-urlencode = "%s"\n' "$kv"; done; } | curl -fsS -K -
}
uapi() { local fn="$1"; shift; curl_call "https://${host}:2083/execute/${fn}" "$@"; }
api2() { local mod="$1" fn="$2"; shift 2; curl_call "https://${host}:2083/json-api/cpanel" "cpanel_jsonapi_apiversion=2" "cpanel_jsonapi_module=${mod}" "cpanel_jsonapi_func=${fn}" "$@"; }
ok() { jq -e '.status == 1' >/dev/null 2>&1 <<<"$1"; }
fail() { echo "Échec cPanel ($1) :" >&2; jq -r '(.errors // ["réponse inattendue"]) | join(" ")' <<<"$2" 2>/dev/null >&2 || true; exit 1; }
cfg_escape() { sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' | awk 'BEGIN{ORS="\\n"} {print}'; }  # valeur multiligne -> chaîne de config curl

doms=$(uapi DomainInfo/domains_data "format=hash"); ok "$doms" || fail DomainInfo "$doms"
home=$(jq -r '.data.main_domain.homedir // empty' <<<"$doms")
if [[ -z "$home" ]]; then home=$(jq -r '.data.main_domain.documentroot' <<<"$doms"); home=${home%/*}; fi
[[ -n "$home" ]] || { echo "Dossier personnel cPanel introuvable." >&2; exit 1; }
prefix=$(basename "$home")   # sur o2switch, le préfixe des bases est le nom du compte cPanel
DB="${prefix}_${DB_SUFFIX}"; DBUSER="${prefix}_${DBUSER_SUFFIX}"

file_exists() { local r; r=$(uapi Fileman/get_file_information "path=$1" 2>/dev/null || true); jq -e '.status == 1 and ((.data // []) | length) > 0' >/dev/null 2>&1 <<<"$r"; }

# ============================================================================ Phase 2
if [[ $MIGRATE -eq 1 ]]; then
  file_exists "$home/$APP_DIR/vendor/autoload.php" || { echo "Le code n'est pas encore déployé (vendor/autoload.php absent). Lancer d'abord le workflow Déploiement app.runeride.fr." >&2; exit 1; }
  file_exists "$home/$APP_DIR/.env" || { echo ".env absent : lancer d'abord la phase 1 (--apply)." >&2; exit 1; }
  log="storage/migrate.log"
  cmd="cd $home/$APP_DIR && sh -c 'for p in /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /usr/local/bin/php /usr/bin/php; do if [ -x \$p ]; then \$p bin/cle-jwt; \$p bin/migrer; echo FIN; exit 0; fi; done; echo AUCUN_PHP' > $log 2>&1"
  echo "== Phase 2 : clé JWT et migrations par tâche cron éphémère =="
  [[ $APPLY -eq 1 || $MIGRATE -eq 1 ]] || exit 0
  add=$(uapi Cron/add_line "command=$cmd" "minute=*" "hour=*" "day=*" "month=*" "weekday=*"); ok "$add" || fail Cron/add_line "$add"
  linekey=$(jq -r '.data.linekey // empty' <<<"$add")
  cleanup() { [[ -n "${linekey:-}" ]] && uapi Cron/remove_line "linekey=$linekey" >/dev/null 2>&1 && echo "tâche cron supprimée" || true; }
  trap cleanup EXIT
  echo "tâche planifiée, attente de son exécution (jusqu'à 3 minutes)…"
  out=""
  for _ in $(seq 1 18); do
    sleep 10
    r=$(uapi Fileman/get_file_content "dir=$home/$APP_DIR/storage" "file=migrate.log" 2>/dev/null || true)
    out=$(jq -r '.data.content // empty' <<<"$r" 2>/dev/null || true)
    if grep -q -E '^(FIN|AUCUN_PHP)' <<<"$out"; then break; fi
  done
  vide="(aucune sortie : la tâche ne s'est pas encore exécutée)"
  echo "--- sortie ---"; echo "${out:-$vide}"
  grep -q '^FIN' <<<"$out" || { echo "! migrations non confirmées" >&2; exit 1; }
  echo "migrations terminées"; exit 0
fi

# ============================================================================ Phase 1
dbs=$(uapi Mysql/list_databases); ok "$dbs" || fail Mysql/list_databases "$dbs"
users=$(uapi Mysql/list_users); ok "$users" || fail Mysql/list_users "$users"
db_exists=$(jq -r --arg d "$DB" '[.data[]? | select(.database == $d)] | length' <<<"$dbs")
dbu_exists=$(jq -r --arg u "$DBUSER" '[.data[]? | select(.user == $u)] | length' <<<"$users")
env_exists=0; file_exists "$home/$APP_DIR/.env" && env_exists=1
need_env=0; [[ "$env_exists" -eq 0 || $FORCE_ENV -eq 1 ]] && need_env=1
vers=$(uapi LangPHP/php_get_installed_versions 2>/dev/null || true)
php_pick=$(jq -r '[.data.versions[]? | select(test("^(ea-php|alt-php)(8[3-9]|9[0-9])"))] | sort | last // empty' <<<"$vers" 2>/dev/null || true)

echo "== Phase 1 (compte $prefix, dossier $home/$APP_DIR) =="
echo "base $DB : $([[ "$db_exists" -gt 0 ]] && echo existe || echo absente)"
echo "utilisateur $DBUSER : $([[ "$dbu_exists" -gt 0 ]] && echo existe || echo absent)"
echo ".env : $([[ "$env_exists" -gt 0 ]] && echo existe || echo absent)"
echo "PHP choisi pour $FQDN : ${php_pick:-(aucune version >= 8.3 détectée, à régler dans cPanel > MultiPHP Manager)}"
echo "== Plan =="
[[ "$db_exists" -gt 0 ]] || echo "+ création de la base $DB"
if [[ "$dbu_exists" -eq 0 ]]; then echo "+ création de l'utilisateur $DBUSER"; elif [[ $need_env -eq 1 ]]; then echo "+ nouveau mot de passe de $DBUSER"; fi
echo "+ droits complets de $DBUSER sur $DB seulement"
echo "+ dossier $home/$APP_DIR/storage"
[[ $need_env -eq 1 ]] && echo "+ écriture de $home/$APP_DIR/.env (secrets générés)" || echo "= .env conservé (ajouter --force-env pour le recréer)"
[[ -z "$php_pick" ]] || echo "+ PHP $php_pick pour $FQDN"
[[ $APPLY -eq 1 ]] || { echo "(simulation : ajouter --apply pour exécuter)"; exit 0; }

[[ "$db_exists" -gt 0 ]] || { r=$(uapi Mysql/create_database "name=$DB"); ok "$r" || fail Mysql/create_database "$r"; echo "base créée : $DB"; }
dbpass=""
if [[ $need_env -eq 1 ]]; then
  dbpass=$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-32)
  if [[ "$dbu_exists" -eq 0 ]]; then r=$(uapi Mysql/create_user "name=$DBUSER" "password=$dbpass"); ok "$r" || fail Mysql/create_user "$r"; echo "utilisateur créé : $DBUSER"
  else r=$(uapi Mysql/set_password "user=$DBUSER" "password=$dbpass"); ok "$r" || fail Mysql/set_password "$r"; echo "mot de passe de $DBUSER changé"; fi
elif [[ "$dbu_exists" -eq 0 ]]; then
  echo ".env existe mais pas l'utilisateur $DBUSER : relancer avec --force-env." >&2; exit 1
fi
r=$(uapi Mysql/set_privileges_on_database "user=$DBUSER" "database=$DB" "privileges=ALL PRIVILEGES"); ok "$r" || fail Mysql/set_privileges_on_database "$r"
echo "droits accordés sur $DB"

r=$(api2 Fileman mkdir "path=$home/$APP_DIR" "name=storage" 2>/dev/null || true)
file_exists "$home/$APP_DIR/storage" && echo "dossier storage prêt" || echo "! dossier storage non confirmé : à créer dans cPanel > Gestionnaire de fichiers (~/app/storage)" >&2

if [[ $need_env -eq 1 ]]; then
  georide=$(openssl rand -base64 32)
  env_text=$(printf '%s\n' \
    "RUNERIDE_ENV=production" \
    "RUNERIDE_BASE_URL=https://$FQDN" \
    "RUNERIDE_DB_DSN=\"mysql:host=localhost;dbname=$DB;charset=utf8mb4\"" \
    "RUNERIDE_DB_USER=$DBUSER" \
    "RUNERIDE_DB_PASSWORD=$dbpass" \
    "RUNERIDE_JWT_KEY_FILE=$home/$APP_DIR/storage/jwt-private.pem" \
    "RUNERIDE_JWT_AUDIENCE=runeride" \
    "RUNERIDE_GEORIDE_KEY=$georide" \
    "RUNERIDE_GEORIDE_URL=https://api.georide.com" \
    "RUNERIDE_FREE_HISTORY_DAYS=30")
  content=$(cfg_escape <<<"$env_text")
  r=$(uapi Fileman/save_file_content "dir=$home/$APP_DIR" "file=.env" "content=$content" "from_charset=utf-8" "to_charset=utf-8")
  ok "$r" || fail Fileman/save_file_content "$r"
  unset dbpass georide env_text content
  echo ".env écrit dans $home/$APP_DIR (hors de la racine web)"
fi

if [[ -n "$php_pick" ]]; then
  r=$(uapi LangPHP/php_set_vhost_versions "vhost=$FQDN" "version=$php_pick" 2>/dev/null || true)
  ok "$r" && echo "PHP $php_pick réglé pour $FQDN" || echo "! version de PHP non réglée : à faire dans cPanel > MultiPHP Manager ($FQDN en PHP >= 8.3)" >&2
fi
unset token
echo "Étape suivante : fusionner sur main (déploiement par la CI), puis : AWS_PROFILE=… ops/app-provision.sh --migrate"
