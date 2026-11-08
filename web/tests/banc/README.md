# Banc d'essai de la PWA (vue Balade), sans AWS

Rejoue dans Chrome headless le parcours complet de `#/balade` : jeton court délivré par le PHP local, calcul par le
**vrai binaire Rust** de Raido (`runeride-carto/rust/target/release/bootstrap`) derrière un mini serveur HTTP local,
enregistrement, partage public, quota de grands calculs. Rien n'est envoyé nulle part.

Prérequis : Docker (MariaDB 11.4, image `ghcr.io/puppeteer/puppeteer`), PHP 8.3+, Python 3, le graphe Rhône-Alpes
(`runeride-carto/donnees/graphe/rhone-alpes`) et le binaire `bootstrap` compilé en release.

```sh
# 1. Base et application (depuis web/)
docker run -d --name rr-mariadb-test -e MARIADB_DATABASE=runeride_test -e MARIADB_USER=runeride -e MARIADB_PASSWORD=runeride -e MARIADB_ROOT_PASSWORD=root -p 127.0.0.1:33306:3306 mariadb:11.4
cp .env.example .env   # DSN 127.0.0.1:33306, RUNERIDE_BASE_URL=http://127.0.0.1:8080, RUNERIDE_RAIDO_URL=http://127.0.0.1:8090
php bin/cle-jwt && php bin/migrer
php -S 127.0.0.1:8080 -t public tests/banc/routeur.php &

# 2. Raido local (le JWKS est lu sur le PHP local, l'émetteur est RUNERIDE_BASE_URL)
RAIDO_SOURCE=local:/chemin/du/graphe RAIDO_CACHE= RAIDO_JETON=obligatoire \
RAIDO_JWKS_URL=http://127.0.0.1:8080/.well-known/jwks.json RAIDO_EMETTEUR=http://127.0.0.1:8080 RAIDO_AUDIENCE=raido \
python3 -I tests/banc/raido-local.py /chemin/de/bootstrap 8090 &

# 3. Un compte et son jeton de session
curl -s -X POST http://127.0.0.1:8080/api/inscription -H 'content-type: application/json' -d '{"email":"banc@example.com","mot_de_passe":"motdepasse-banc-123"}'
mkdir -p /tmp/banc && curl -s -X POST http://127.0.0.1:8080/api/connexion -H 'content-type: application/json' -d '{"email":"banc@example.com","mot_de_passe":"motdepasse-banc-123"}' | python3 -c 'import json,sys; print(json.load(sys.stdin)["jeton"])' > /tmp/banc/jeton.txt

# 4. Le scénario (captures dans /tmp/banc/captures)
mkdir -p /tmp/banc/captures && chmod 777 /tmp/banc/captures && cp tests/banc/scenario-balade.mjs /tmp/banc/scenario.mjs
docker run --rm --network host -v /tmp/banc:/banc -w /home/pptruser ghcr.io/puppeteer/puppeteer:24.9.0 sh -c 'cp /banc/scenario.mjs . && node scenario.mjs'
```

18 vérifications (voir le scénario) ; sortie `TOUT OK` et code 0 si tout passe. Entre deux passages :
`DELETE FROM balades; DELETE FROM balade_quotas;` (le quota est mensuel).

## Étapes

`scenario-etapes.mjs` (même installation, même commande en remplaçant le scénario copié dans `/tmp/banc/scenario.mjs`) : pose de deux étapes sur la carte,
réordonnancement ▲ ▼, retrait, calcul par le vrai moteur (distance au passage de chaque étape affichée), enregistrement puis rechargement de la balade
(les étapes reviennent dans le même ordre). Le graphe doit être au **format 4** (`rhone-alpes-j3`). Une exécution compte un grand calcul : vider
`balades` et `balade_quotas` avant `scenario-balade.mjs`, qui attend 3 grands calculs restants.

## Autonomie

`scenario-autonomie.mjs` : compte **abonné** (`UPDATE users SET tier='abonne'` après l'inscription), stations simulées par interception de
`/api/ravitaillement` (grille de 2 km autour d'Annecy), vrai moteur, graphe de format 4. Vérifie le champ, l'envoi de `autonomie` et de `stations`
à Raido, les pleins affichés (distance, prix) et que l'enregistrement ne garde pas la liste des stations. Avec un compte abonné,
`scenario-balade.mjs` (quota de gratuits) et la ligne « grand itinéraire » de `scenario-etapes.mjs` ne s'appliquent plus.
