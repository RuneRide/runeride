# Plan RuneRide — baseline

Dépôt neuf, inspiré de l'existant (Bike360 / `insta-build`, NomadRide), rien n'est renommé là-bas.

## Lots

1. **Socle PHP** : structure `web/` (`public/`, `src/`), Composer, configuration par variables d'environnement, déploiement o2switch (cPanel, PHP 8.x, MariaDB).
2. **Vitrine** : reprise de `site/` de Bike360 sous le nom RuneRide (CGV, mentions légales, confidentialité, guide d'import Insta360).
3. **Comptes et API** : inscription, connexion, mot de passe oublié, suppression de compte, jetons ; connexion Google. Contrat partagé avec le cloud AWS (à définir : jeton émis par la baseline ou par Cognito).
4. **Trajets GeoRide et ravitaillement** : portage de NomadRide (carte des trajets gratuite, historique récent ; historique complet et arrêts carburant aux abonnés).
5. **Passerelle vers le cloud** : lien du compte vers `runeride-cloud`, palier d'abonnement lu côté baseline.

## Points à trancher

- Où vivent les comptes de référence : baseline (MariaDB) ou Cognito ?
- Durée de l'historique gratuit des trajets (30 jours proposés).
- Règle de l'essai et conditions d'usage commercial d'OpenStreetMap/CARTO.
