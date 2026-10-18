import { FOND_DE_CARTE, TUILES_VECTORIELLES, CENTRE_DEFAUT, ZOOM_DEFAUT } from './config.js';

const COULEURS = ['#C6934B', '#3b82f6', '#10b981', '#ec4899', '#8b5cf6', '#06b6d4'];
const ACTIF = '#ef4444';
export const couleurTrajet = (i) => COULEURS[i % COULEURS.length];

/**
 * Crée une carte Leaflet dans `conteneur`. Leaflet est chargé par <script defer> : on attend `load` au besoin.
 * `carte.marge()` (à définir par la vue) donne l'espace masqué par un panneau, en pixels, pour cadrer les tracés.
 */
export function creerCarte(conteneur) {
  if (typeof window.L === 'undefined') {
    conteneur.textContent = 'La carte n\'a pas pu être chargée (bibliothèque indisponible). Vérifiez votre connexion.';
    return null;
  }
  const L = window.L;
  const carte = L.map(conteneur, { scrollWheelZoom: true, zoomControl: false }).setView(CENTRE_DEFAUT, ZOOM_DEFAUT);
  // Pas de boutons de zoom au doigt (pincer) ; en haut à droite sur ordinateur, l'attribution en haut à gauche (le panneau couvre le bas).
  if (!L.Browser.mobile) L.control.zoom({ position: 'topright', zoomInTitle: 'Zoom avant', zoomOutTitle: 'Zoom arrière' }).addTo(carte);
  carte.attributionControl.setPosition('topleft');
  const raster = L.tileLayer(FOND_DE_CARTE.url, {
    attribution: FOND_DE_CARTE.attribution,
    ...(FOND_DE_CARTE.sousDomaines ? { subdomains: FOND_DE_CARTE.sousDomaines } : {}),
    maxZoom: FOND_DE_CARTE.zoomMax,
  });
  const vectoriel = creerCoucheVectorielle(L);
  if (!vectoriel) { raster.addTo(carte); return carte; }
  // Un seul fond à la fois : les tuiles vectorielles quand toute la vue est dans la zone couverte (sinon des plages grises), OSM sinon.
  const zone = L.latLngBounds(TUILES_VECTORIELLES.limites);
  const choisir = () => {
    const dedans = zone.contains(carte.getBounds());
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

/** Cadre `points` en tenant compte de la marge (panneau) définie par la vue. */
export function cadrer(carte, points, { maxZoom = 14, animer = true } = {}) {
  if (!points.length) return;
  const m = (carte.marge && carte.marge()) || {};
  carte.fitBounds(window.L.latLngBounds(points), {
    paddingTopLeft: [(m.gauche || 0) + 30, (m.haut || 0) + 30],
    paddingBottomRight: [(m.droite || 0) + 30, (m.bas || 0) + 30],
    maxZoom, animate: animer,
  });
}

/** Dessine des trajets ({id, titre, trace:[[lat,lon]…]}) ; rend { polylignes, ajuster, activer }. */
export function dessinerTrajets(carte, trajets, { onClic } = {}) {
  const L = window.L;
  const polylignes = new Map();
  trajets.forEach((t, i) => {
    if (!t.trace || t.trace.length < 2) return;
    const ligne = L.polyline(t.trace, { color: couleurTrajet(i), weight: 3.5, opacity: 0.75, lineJoin: 'round' }).addTo(carte);
    ligne.bindTooltip(t.titre || 'Trajet', { sticky: true });
    if (onClic) ligne.on('click', () => onClic(t.id));
    polylignes.set(t.id, { ligne, couleur: couleurTrajet(i), trace: t.trace });
  });
  const ajuster = (ids) => {
    const points = [...polylignes.entries()].filter(([id]) => !ids || ids.includes(id)).flatMap(([, p]) => p.trace);
    cadrer(carte, points);
  };
  /** Met en avant un trajet (null : tous au repos). `cadrer` : recentre sur le trajet. */
  const activer = (idActif, { cadrer: recentrer = true } = {}) => {
    for (const [id, p] of polylignes) {
      const actif = id === idActif;
      p.ligne.setStyle({ color: actif ? ACTIF : p.couleur, weight: actif ? 6 : 3.5, opacity: actif || idActif == null ? 0.9 : 0.3 });
      if (actif) p.ligne.bringToFront();
    }
    if (idActif != null && recentrer) ajuster([idActif]);
  };
  ajuster();
  return { polylignes, ajuster, activer };
}

export function marqueur(carte, [lat, lon], texte, couleur = '#C6934B') {
  const L = window.L;
  return L.circleMarker([lat, lon], { radius: 8, color: '#fff', weight: 2, fillColor: couleur, fillOpacity: 1 }).addTo(carte).bindTooltip(texte);
}

/** Pastille de prix sur la carte (le texte vient de nos formats numériques, pas de l'utilisateur). */
export function marqueurPrix(carte, [lat, lon], texte, { meilleur = false, onClic } = {}) {
  const L = window.L;
  const icone = L.divIcon({ className: '', html: `<span class="pastille-prix${meilleur ? ' meilleur' : ''}">${texte}</span>`, iconSize: [0, 0] });
  const m = L.marker([lat, lon], { icon: icone, keyboard: false }).addTo(carte);
  if (onClic) m.on('click', () => onClic());
  m.activer = (actif) => { const el = m.getElement()?.firstElementChild; if (el) el.classList.toggle('actif', actif); m.setZIndexOffset(actif ? 1000 : 0); };
  return m;
}

/** Repère de position, déplaçable : `onDeplace([lat, lon])` après un glisser. */
export function marqueurPosition(carte, [lat, lon], { onDeplace } = {}) {
  const L = window.L;
  const icone = L.divIcon({ className: '', html: '<span class="repere-position"></span>', iconSize: [24, 24], iconAnchor: [12, 12] });
  const m = L.marker([lat, lon], { icon: icone, draggable: Boolean(onDeplace), keyboard: false, zIndexOffset: 2000 }).addTo(carte);
  if (onDeplace) m.on('dragend', () => { const p = m.getLatLng(); onDeplace([p.lat, p.lng]); });
  return m;
}
