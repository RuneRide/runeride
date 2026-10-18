# RuneRide

Baseline gratuite de RuneRide : site vitrine, comptes et API de comptes, trajets GeoRide et ravitaillement (héritage de NomadRide).

- Hébergement : o2switch (runeride.fr, runeride.com, runeride.eu)
- Les rushs des utilisateurs restent stockés sur S3 (cloud AWS), jamais sur o2switch.
- Langage : PHP 8.x moderne, MySQL/MariaDB
- Le cloud (stockage, montage, export GPU) vit dans le dépôt `runeride-cloud` (AWS, Rust).

Plan des lots : [docs/plan.md](docs/plan.md)

## Tâches planifiées

- `web/bin/renouveler-jetons-georide` : à lancer une fois par jour (cron o2switch). Renouvelle les jetons GeoRide de 7 jours ou plus, avant leur expiration à 30 jours ; un jeton que GeoRide refuse passe le compte en « à reconnecter ». Code de sortie 1 si GeoRide est injoignable ou en erreur.
