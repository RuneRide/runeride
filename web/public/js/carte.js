import { FOND_DE_CARTE, TUILES_VECTORIELLES, CENTRE_DEFAUT, ZOOM_DEFAUT } from './config.js';

const COULEURS = ['#C6934B', '#3b82f6', '#10b981', '#ec4899', '#8b5cf6', '#06b6d4'];
const ACTIF = '#ef4444';

/** Crée une carte Leaflet dans `conteneur`. Leaflet est chargé par <script defer> : on attend `load` au besoin. */
export function creerCarte(conteneur) {
  if (typeof window.L === 'undefined') {
    conteneur.textContent = 'La carte n\'a pas pu être chargée (bibliothèque indisponible). Vérifiez votre connexion.';
    return null;
  }
  const L = window.L;
  const carte = L.map(conteneur, { scrollWheelZoom: true }).setView(CENTRE_DEFAUT, ZOOM_DEFAUT);
  const raster = L.tileLayer(FOND_DE_CARTE.url, {
    attribution: FOND_DE_CARTE.attribution,
    ...(FOND_DE_CARTE.sousDomaines ? { subdomains: FOND_DE_CARTE.sousDomaines } : {}),
    maxZoom: FOND_DE_CARTE.zoomMax,
  });
  const vectoriel = creerCoucheVectorielle(L);
  if (!vectoriel) { raster.addTo(carte); return carte; }
  // Un seul fond à la fois : les tuiles vectorielles quand le centre de la vue est dans la zone couverte, OSM sinon.
  const zone = L.latLngBounds(TUILES_VECTORIELLES.limites);
  const choisir = () => {
    const dedans = zone.contains(carte.getCenter());
    if (dedans && !carte.hasLayer(vectoriel)) { raster.remove(); vectoriel.addTo(carte); }
    else if (!dedans && !carte.hasLayer(raster)) { vectoriel.remove(); raster.addTo(carte); }
  };
  carte.on('moveend', choisir);
  choisir();
  return carte;
}

/** Couche PMTiles (protomaps-leaflet, chargé par <script defer>) ; null si indisponible ou désactivée. */
function creerCoucheVectorielle(L) {
  if (!TUILES_VECTORIELLES.url || !window.protomapsL) return null;
  const sombre = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  try {
    return window.protomapsL.leafletLayer({
      url: TUILES_VECTORIELLES.url,
      flavor: sombre ? 'dark' : 'light',
      lang: 'fr',
      maxDataZoom: TUILES_VECTORIELLES.zoomDonnees,
      attribution: TUILES_VECTORIELLES.attribution,
    });
  } catch (e) {
    return null;
  }
}

/** Dessine des trajets ({id, titre, trace:[[lat,lon]…]}) ; rend { polylignes, ajuster, activer }. */
export function dessinerTrajets(carte, trajets, { onClic } = {}) {
  const L = window.L;
  const polylignes = new Map();
  trajets.forEach((t, i) => {
    if (!t.trace || t.trace.length < 2) return;
    const ligne = L.polyline(t.trace, { color: COULEURS[i % COULEURS.length], weight: 3.5, opacity: 0.65, lineJoin: 'round' }).addTo(carte);
    ligne.bindTooltip(t.titre || 'Trajet', { sticky: true });
    if (onClic) ligne.on('click', () => onClic(t.id));
    polylignes.set(t.id, { ligne, couleur: COULEURS[i % COULEURS.length], trace: t.trace });
  });
  const ajuster = (ids) => {
    const points = [...polylignes.entries()].filter(([id]) => !ids || ids.includes(id)).flatMap(([, p]) => p.trace);
    if (points.length) carte.fitBounds(L.latLngBounds(points), { padding: [30, 30], maxZoom: 14 });
  };
  const activer = (idActif) => {
    for (const [id, p] of polylignes) {
      const actif = id === idActif;
      p.ligne.setStyle({ color: actif ? ACTIF : p.couleur, weight: actif ? 6 : 3.5, opacity: actif || idActif == null ? 1 : 0.4 });
      if (actif) p.ligne.bringToFront();
    }
    if (idActif != null) ajuster([idActif]);
  };
  ajuster();
  return { polylignes, ajuster, activer };
}

export function marqueur(carte, [lat, lon], texte, couleur = '#C6934B') {
  const L = window.L;
  return L.circleMarker([lat, lon], { radius: 8, color: '#fff', weight: 2, fillColor: couleur, fillOpacity: 1 }).addTo(carte).bindTooltip(texte);
}
