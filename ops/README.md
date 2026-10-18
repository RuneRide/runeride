# Exploitation : app.runeride.fr sur o2switch

L'application (PWA et API PHP) est servie par `app.runeride.fr` ; la vitrine est un site à part (dépôt `RuneRide/website`, `runeride.fr`).

Disposition sur le serveur : tout le projet `web/` vit dans `~/app` ; la racine web est `~/app/public`. Hors de la racine web : `.env`, `storage/` (clé JWT), `src/`, `vendor/`, `bin/`, `migrations/`.

## Mise en place, une seule fois

Depuis un poste avec les accès AWS (profil `bike360`) et `gh` connecté. Le jeton cPanel et les secrets générés ne sont jamais affichés.

```sh
AWS_PROFILE=bike360 ops/app-setup.sh --apply      # sous-domaine, compte FTP deploy-app, secrets GitHub
AWS_PROFILE=bike360 ops/app-provision.sh --apply  # base MariaDB, ~/app/.env, version de PHP
# fusionner sur main : la CI teste puis déploie (.github/workflows/deploy-app.yml)
AWS_PROFILE=bike360 ops/app-provision.sh --migrate  # clé JWT et migrations (tâche cron éphémère)
```

Chaque script sans option est une simulation. Après la mise en place, plus rien à faire à la main : un push sur `main` qui touche `web/` redéploie.

## Ce que la CI ne touche jamais

`.env`, `storage/**`, `tests/**`, `composer.lock`, et `vitrine/**` (anciennes pages de la vitrine, archivées hors de la racine web ; la vitrine vit dans `RuneRide/website`).

L'interface (PWA) est à la racine de `app.runeride.fr` ; l'ancienne adresse `/app/…` redirige (301) vers `/…`, et `app/sw.js` est un ancien service worker qui se désinstalle.
