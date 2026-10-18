# Plan RuneRide — baseline

Dépôt neuf, inspiré de l'existant (Bike360 / `insta-build`, NomadRide), rien n'est renommé là-bas.

## Lots

1. **Socle PHP** : structure `web/` (`public/`, `src/`), Composer, configuration par variables d'environnement, déploiement o2switch (cPanel, PHP 8.x, MariaDB).
2. **Vitrine** : reprise de `site/` de Bike360 sous le nom RuneRide (CGV, mentions légales, confidentialité, guide d'import Insta360).
3. **Comptes et API** (fait : inscription, connexion, JWT RS256, JWKS, /api/moi ; reste : Google, mot de passe oublié, suppression) (décidé : MariaDB sur o2switch = source de vérité) : inscription, connexion, mot de passe oublié, suppression de compte, connexion Google ; émission de JWT RS256 et publication des clés (JWKS) vérifiées par le cloud AWS.
4. **Trajets GeoRide et ravitaillement** (fait côté API : migrations 003-007, synchro GeoRide par jeton renouvelable sans mot de passe stocké (migrations 008-009), trajets, tracés, arrêts carburant France, palier gratuit/abonné ; fait côté interface : `web/public/app/`, PWA sans étape de build (connexion/inscription, liste et carte Leaflet 1.9.4, détail, GeoRide, synchronisation, pit-stop, service worker, manifeste, icônes RuneRide) ; reste : autres pays, préférences, enrichissement des marques, test sur iPhone/Android réels) : portage de NomadRide (carte des trajets gratuite, historique récent ; historique complet et arrêts carburant aux abonnés).
5. **Passerelle vers le cloud** : lien du compte vers `runeride-cloud`, palier d'abonnement lu côté baseline.

## Accès SQL : Doctrine DBAL (adopté)

Tout l'accès aux données passe par `doctrine/dbal` ^4.4 (comme `runeride-carto`) : `Database::connect()` renvoie une `Connection` paresseuse construite depuis le DSN PDO du `.env` (MariaDB `pdo_mysql`, PostgreSQL `pdo_pgsql`), les dépôts (`Auth/`, `Trips/`, `Fuel/`) utilisent le QueryBuilder et `insert`/`update`/`delete`, `LIMIT`/`OFFSET` passent par `setMaxResults`/`setFirstResult`, et les remplacements de stations par lots sont un `INSERT` multi-lignes à paramètres nommés générés (valeurs toujours liées, noms de colonnes constants). Les migrations gardent le format `migrations/<pilote>/*.sql` et la table `migrations` (une instruction par fichier). Horodatages toujours générés en PHP.

## Points à trancher

- Durée de l'historique gratuit des trajets (30 jours par défaut, réglable par `RUNERIDE_FREE_HISTORY_DAYS` en attendant la décision).
- Règle de l'essai et conditions d'usage commercial des tuiles de carte : l'interface utilise les tuiles OpenStreetMap (usage modéré seulement) ; les tuiles CARTO de NomadRide affichent « API KEY REQUIRED » et sont écartées. Avant la prod payante : fournisseur sous contrat ou tuiles hébergées (constante `FOND_DE_CARTE` dans `web/public/app/js/config.js`).
- Liens de la vitrine sans cible : `/ui/compte.html` (11 occurrences), `/ui/envoi.html`, `/ui/bibliotheque.html`. `/app/` couvre déjà connexion/inscription : à rediriger ou à réécrire.

## Carto

Les outils OSM et le PoC de tuiles PMTiles ont été déplacés vers le dépôt `runeride-carto` (voir son `docs/plan.md`).
