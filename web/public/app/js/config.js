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

// Centre par défaut (France) quand aucun trajet n'est affiché.
export const CENTRE_DEFAUT = [46.6, 2.5];
export const ZOOM_DEFAUT = 5;

// Où envoyer quelqu'un qui doit s'abonner (tarifs du site vitrine, sur un autre domaine).
export const URL_ABONNEMENT = 'https://runeride.fr/tarifs/';

export const CLE_JETON = 'runeride.jeton';
