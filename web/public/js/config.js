// Réglages de l'interface. Aucune clé d'API ici.

// Fond de carte (une seule constante à changer pour changer de fournisseur ; aucune clé d'API).
// À TRANCHER avant mise en production commerciale : la politique d'usage des tuiles d'OpenStreetMap
// (https://operations.osmfoundation.org/policies/tiles/) n'autorise qu'un usage modéré et sans garantie ;
// un service payant devrait passer par un fournisseur de tuiles sous contrat ou les héberger (voir docs/plan.md).
// Les tuiles CARTO « rastertiles/voyager » utilisées par NomadRide affichent désormais « API KEY REQUIRED » : écartées.
export const FOND_DE_CARTE = {
  url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">contributeurs OpenStreetMap</a>',
  sousDomaines: '',
  zoomMax: 19,
};

// Tuiles vectorielles PMTiles (Protomaps/OpenStreetMap), servies par carto.runeride.fr et lues par requêtes Range.
// Elles ne couvrent qu'une zone (Lyon, AURA, PACA, Bourgogne) : hors de cette zone, on retombe sur FOND_DE_CARTE.
// Passer `url` à null pour désactiver. `limites` = [[sud, ouest], [nord, est]].
export const TUILES_VECTORIELLES = {
  url: 'https://carto.runeride.fr/tuiles/aura-paca-bourgogne.pmtiles',
  limites: [[43.0, 1.9], [48.4, 7.8]],
  zoomDonnees: 15,
  // Lecture directe par position (?o=&l=) : le serveur d'o2switch lit les fichiers statiques depuis le début jusqu'à la
  // position demandée (3 à 4 s en fin de fichier), alors que plage.php va directement au bon endroit (~0,1 s).
  // `version` change quand le fichier est remplacé (les réponses sont immuables côté navigateur et serveur).
  // Passer `lecture` à null pour lire `url` par requêtes Range classiques (ex. depuis S3 + CloudFront).
  lecture: { url: 'https://carto.runeride.fr/tuiles/plage.php', fichier: 'aura-paca-bourgogne', version: '20261018' },
  attribution: '&copy; <a href="https://protomaps.com">Protomaps</a> &copy; <a href="https://www.openstreetmap.org/copyright">contributeurs OpenStreetMap</a>',
};

// Centre par défaut (France) quand aucun trajet n'est affiché.
export const CENTRE_DEFAUT = [46.6, 2.5];
export const ZOOM_DEFAUT = 5;

// Où envoyer quelqu'un qui doit s'abonner (tarifs du site vitrine, sur un autre domaine).
export const URL_ABONNEMENT = 'https://runeride.fr/tarifs/';

export const CLE_JETON = 'runeride.jeton';
