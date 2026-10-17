#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# Crée app.runeride.fr sur o2switch (cPanel) : sous-domaine avec sa racine web ~/app/public, compte FTP de
# déploiement enfermé dans ~/app, et secrets GitHub Actions du dépôt RuneRide/runeride
# (FTP_SERVER, FTP_USERNAME, FTP_PASSWORD) utilisés par .github/workflows/deploy-app.yml.
#
# Le jeton cPanel (AWS Secrets Manager : runeride/cpanel) et le mot de passe FTP généré ne sont jamais affichés,
# écrits sur disque ni passés en argument de commande (ils transitent par stdin).
#
# Usage :
#   AWS_PROFILE=bike360 ops/app-setup.sh                   # simulation
#   AWS_PROFILE=bike360 ops/app-setup.sh --apply           # crée sous-domaine, compte FTP et secrets
#   AWS_PROFILE=bike360 ops/app-setup.sh --apply --rotate  # change le mot de passe d'un compte FTP existant
#
# Prérequis : aws (profil avec accès à Secrets Manager), gh (droit d'écrire les secrets), curl, jq, openssl, dig.
set -euo pipefail

SECRET_ID="runeride/cpanel"; REGION="eu-north-1"; ROOT_DOMAIN="runeride.fr"
SUB="app"; FQDN="${SUB}.${ROOT_DOMAIN}"; SUB_DIR="app/public"
FTP_USER="deploy-app"; FTP_HOME="app"; LOGIN="${FTP_USER}@${ROOT_DOMAIN}"; REPO="RuneRide/runeride"

APPLY=0; ROTATE=0
for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=1 ;;
    --rotate) ROTATE=1 ;;
    *) echo "Option inconnue : $arg" >&2; exit 2 ;;
  esac
done

secret=$(aws secretsmanager get-secret-value --region "$REGION" --secret-id "$SECRET_ID" --query SecretString --output text)
pick() { jq -r --arg keys "$1" '[ ($keys | split(",")[]) as $k | .[$k]? | select(. != null and . != "") ] | first // empty' <<<"$secret"; }
host=$(pick host,hostname,server,url,cpanel_host)
user=$(pick user,username,login,cpanel_user)
token=$(pick token,api_token,apitoken,key,secret)
host=${host#*://}; host=${host%%/*}; host=${host%%:*}
[[ -n "$host" && -n "$user" && -n "$token" ]] || { echo "Secret inattendu (hôte, utilisateur ou jeton manquant)." >&2; exit 1; }
unset secret

curl_call() {  # $1 = URL ; puis paires clé=valeur. Jeton et valeurs passent par stdin (pas dans argv).
  local url="$1"; shift
  {
    printf 'header = "Authorization: cpanel %s:%s"\n' "$user" "$token"
    printf 'url = "%s"\n' "$url"
    printf 'get\n'
    for kv in "$@"; do printf 'data-urlencode = "%s"\n' "$kv"; done
  } | curl -fsS -K -
}
uapi() { local fn="$1"; shift; curl_call "https://${host}:2083/execute/${fn}" "$@"; }
api2() { local mod="$1" fn="$2"; shift 2; curl_call "https://${host}:2083/json-api/cpanel" "cpanel_jsonapi_apiversion=2" "cpanel_jsonapi_module=${mod}" "cpanel_jsonapi_func=${fn}" "$@"; }
ok() { jq -e '.status == 1' >/dev/null 2>&1 <<<"$1"; }
fail() { echo "Échec cPanel :" >&2; jq -r '(.errors // ["réponse inattendue"]) | join(" ")' <<<"$1" 2>/dev/null >&2 || true; exit 1; }

doms=$(uapi DomainInfo/domains_data "format=hash"); ok "$doms" || fail "$doms"
sub_exists=$(jq -r --arg d "$FQDN" '[.data.sub_domains[]? | select(.domain == $d)] | length' <<<"$doms")
ftps=$(uapi Ftp/list_ftp); ok "$ftps" || fail "$ftps"
ftp_exists=$(jq -r --arg l "$LOGIN" '[.data[]? | select((.user // .serverlogin // "") == $l)] | length' <<<"$ftps")

echo "== État de $FQDN =="
if [[ "$sub_exists" -gt 0 ]]; then jq -r --arg d "$FQDN" '.data.sub_domains[] | select(.domain == $d) | "sous-domaine : \(.domain) -> \(.documentroot)"' <<<"$doms"; else echo "sous-domaine : absent"; fi
echo "compte FTP $LOGIN : $([[ "$ftp_exists" -gt 0 ]] && echo existe || echo absent)"
echo "DNS actuel   : $(dig +short A "$FQDN" | tr '\n' ' ')"

echo "== Plan =="
[[ "$sub_exists" -gt 0 ]] && echo "= sous-domaine $FQDN déjà présent" || echo "+ sous-domaine $FQDN, racine web ~/$SUB_DIR"
if [[ "$ftp_exists" -gt 0 && $ROTATE -eq 0 ]]; then echo "= compte FTP déjà présent (ajouter --rotate pour changer son mot de passe et remettre les secrets)"
elif [[ "$ftp_exists" -gt 0 ]]; then echo "+ rotation du mot de passe de $LOGIN"
else echo "+ compte FTP $LOGIN, dossier ~/$FTP_HOME"; fi
[[ "$ftp_exists" -gt 0 && $ROTATE -eq 0 ]] || echo "+ secrets GitHub FTP_SERVER, FTP_USERNAME, FTP_PASSWORD sur $REPO"
echo "+ lancement du contrôle AutoSSL pour le certificat de $FQDN"
[[ $APPLY -eq 1 ]] || { echo "(simulation : ajouter --apply pour exécuter)"; exit 0; }

if [[ "$sub_exists" -eq 0 ]]; then
  res=$(api2 SubDomain addsubdomain "domain=$SUB" "rootdomain=$ROOT_DOMAIN" "dir=$SUB_DIR")
  if [[ "$(jq -r '.cpanelresult.data[0].result // 0' <<<"$res")" != "1" ]]; then
    echo "Échec de la création du sous-domaine :" >&2
    jq -r '.cpanelresult.data[0].reason // .cpanelresult.error // "réponse inattendue"' <<<"$res" >&2; exit 1
  fi
  echo "sous-domaine créé : $FQDN"
fi

if [[ "$ftp_exists" -eq 0 || $ROTATE -eq 1 ]]; then
  pass=$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-32)
  if [[ "$ftp_exists" -gt 0 ]]; then res=$(uapi Ftp/passwd "user=$FTP_USER" "pass=$pass" "domain=$ROOT_DOMAIN")
  else res=$(uapi Ftp/add_ftp "user=$FTP_USER" "pass=$pass" "homedir=$FTP_HOME" "quota=0" "domain=$ROOT_DOMAIN"); fi
  ok "$res" || fail "$res"
  echo "compte FTP prêt : $LOGIN"
  if printf 'user = "%s:%s"\nssl-reqd\nlist-only\nurl = "ftp://%s/"\n' "$LOGIN" "$pass" "$host" | curl -fsS -K - >/dev/null 2>&1; then
    echo "connexion FTPS réussie sur $host"
  else
    echo "! connexion FTPS impossible sur $host (secrets posés quand même, à vérifier)" >&2
  fi
  printf '%s' "$host"  | gh secret set FTP_SERVER   --repo "$REPO" >/dev/null
  printf '%s' "$LOGIN" | gh secret set FTP_USERNAME --repo "$REPO" >/dev/null
  printf '%s' "$pass"  | gh secret set FTP_PASSWORD --repo "$REPO" >/dev/null
  unset pass
  echo "secrets GitHub posés sur $REPO"
fi

ssl=$(uapi SSL/start_autossl_check 2>/dev/null || true)
if jq -e '.status == 1' >/dev/null 2>&1 <<<"$ssl"; then echo "contrôle AutoSSL lancé (le certificat peut demander quelques minutes à quelques heures)"; else echo "! contrôle AutoSSL non lancé : le certificat sera émis lors du prochain passage automatique d'AutoSSL"; fi
unset token
echo "DNS de $FQDN : $(dig +short A "$FQDN" | tr '\n' ' ')"
echo "Étape suivante : ops/app-provision.sh (base de données, .env, clé JWT) puis fusion sur main pour déclencher le déploiement."
