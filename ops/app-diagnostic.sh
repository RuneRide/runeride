#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
#
# Affiche les dernières lignes des journaux d'erreurs PHP de l'application sur o2switch (cPanel), pour comprendre un HTTP 500.
# Le jeton cPanel ne passe jamais en argument ni à l'écran. Les journaux peuvent citer des chemins mais pas de secrets.
#
# Usage : AWS_PROFILE=bike360 ops/app-diagnostic.sh [nombre_de_lignes]       journaux d'erreurs (défaut 25 lignes)
#         AWS_PROFILE=bike360 ops/app-diagnostic.sh --ls [dossier relatif à ~]  liste un dossier, fichiers cachés compris (défaut app)
set -euo pipefail

SECRET_ID="runeride/cpanel"; REGION="eu-north-1"
MODE=logs; N=25; LSDIR=app
if [[ "${1:-}" == "--ls" ]]; then MODE="ls"; LSDIR="${2:-app}"; else N="${1:-25}"; fi
secret=$(aws secretsmanager get-secret-value --region "$REGION" --secret-id "$SECRET_ID" --query SecretString --output text)
pick() { jq -r --arg keys "$1" '[ ($keys | split(",")[]) as $k | .[$k]? | select(. != null and . != "") ] | first // empty' <<<"$secret"; }
host=$(pick host,hostname,server,url,cpanel_host); user=$(pick user,username,login,cpanel_user); token=$(pick token,api_token,apitoken,key,secret)
host=${host#*://}; host=${host%%/*}; host=${host%%:*}
[[ -n "$host" && -n "$user" && -n "$token" ]] || { echo "Secret inattendu." >&2; exit 1; }
unset secret

uapi() {
  local fn="$1"; shift
  { printf 'header = "Authorization: cpanel %s:%s"\n' "$user" "$token"; printf 'url = "https://%s:2083/execute/%s"\n' "$host" "$fn"; printf 'get\n'
    for kv in "$@"; do printf 'data-urlencode = "%s"\n' "$kv"; done; } | curl -fsS -K -
}
doms=$(uapi DomainInfo/domains_data "format=hash")
home=$(jq -r '.data.main_domain.homedir // empty' <<<"$doms")
[[ -n "$home" ]] || { echo "Dossier personnel introuvable." >&2; exit 1; }

if [[ "$MODE" == "ls" ]]; then
  r=$(uapi Fileman/list_files "dir=$home/$LSDIR" "include_hidden=1" "types=file|dir")
  jq -e '.status == 1' >/dev/null 2>&1 <<<"$r" || { echo "Échec :" >&2; jq -r '(.errors // ["réponse inattendue"]) | join(" ")' <<<"$r" >&2; exit 1; }
  echo "=== $home/$LSDIR ==="
  jq -r '.data[]? | [.type, .file, (.humansize // .size | tostring), ((.mtime // 0) | tonumber | strftime("%Y-%m-%d %H:%M"))] | @tsv' <<<"$r" | sort -k2
  unset token; exit 0
fi

for dir in "$home/app/public" "$home/app" "$home/logs"; do
  for f in error_log php_errorlog; do
    r=$(uapi Fileman/get_file_content "dir=$dir" "file=$f" 2>/dev/null || true)
    c=$(jq -r '.data.content // empty' <<<"$r" 2>/dev/null || true)
    [[ -n "$c" ]] || continue
    echo "=== $dir/$f (dernières $N lignes) ==="
    tail -n "$N" <<<"$c" | cut -c1-300
    echo
  done
done
echo "(fin ; rien affiché ci-dessus = aucun journal d'erreurs trouvé à ces emplacements)"
unset token
