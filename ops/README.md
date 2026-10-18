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

## Contrôle après déploiement et envoi complet

Le workflow se termine par un contrôle de santé : `/api/sante` doit répondre `{"statut":"ok"…}` (HTTP 200), `/` et `/.well-known/jwks.json` aussi (jusqu'à 12 essais espacés de 10 s, le cache PHP d'o2switch mettant ~30 s à voir les nouveaux fichiers). Sinon le workflow échoue.

Le déploiement FTPS est incrémental : il compare avec son fichier d'état `~/app/.ftp-deploy-sync-state.json`, pas avec le serveur. Si deux déploiements se suivent de très près ou si un envoi est interrompu, `vendor/` peut mélanger deux versions (erreur PHP `Class "ComposerAutoloaderInit…" not found`, tout l'API en 500). Réparation : supprimer ce fichier d'état sur le serveur (il n'apparaît pas dans le listage cPanel des fichiers, mais se lit et se supprime par son nom), puis relancer `gh workflow run deploy-app.yml --ref main` : l'envoi est alors complet.
