import { lireJeton, ecrireJeton } from './api.js';
import { vueConnexion, vueTrajets, vueTrajet, vueGeoride, vueRavitaillement } from './vues.js';
import { h, bandeau } from './dom.js';

const racine = document.getElementById('vue');
const nav = document.getElementById('nav');
const boutonSortie = document.getElementById('deconnexion');
let nettoyage = null;
let jetonRendu = 0;

export const aller = (chemin) => { window.location.hash = `#${chemin}`; };

function analyser() {
  const [chemin, requete = ''] = (window.location.hash.slice(1) || '/').split('?');
  return { segments: chemin.split('/').filter(Boolean), params: new URLSearchParams(requete) };
}

async function rendre() {
  const { segments, params } = analyser();
  const connecte = Boolean(lireJeton());
  nav.hidden = boutonSortie.hidden = !connecte;
  const [route, arg] = segments;

  if (typeof nettoyage === 'function') { try { nettoyage(); } catch { /* carte déjà détruite */ } }
  nettoyage = null;
  const moi = ++jetonRendu;

  const publique = route === 'connexion';
  if (!connecte && !publique) { aller(params.get('inscription') ? '/connexion?inscription=1' : '/connexion'); return; }
  if (connecte && publique) { aller('/trajets'); return; }

  nav.querySelectorAll('a').forEach((a) => a.classList.toggle('actif', a.dataset.route === route || (route === 'trajet' && a.dataset.route === 'trajets')));
  const ctx = { aller, params, id: arg };
  let vue;
  switch (route) {
    case 'connexion': vue = vueConnexion; break;
    case 'trajets': vue = vueTrajets; break;
    case 'trajet': vue = vueTrajet; break;
    case 'georide': vue = vueGeoride; break;
    case 'ravitaillement': vue = vueRavitaillement; break;
    default: aller('/trajets'); return;
  }
  document.title = `RuneRide — ${{ connexion: 'connexion', trajets: 'mes trajets', trajet: 'trajet', georide: 'GeoRide', ravitaillement: 'pit-stop' }[route]}`;
  try {
    const res = await vue(racine, ctx);
    if (moi !== jetonRendu) { if (typeof res === 'function') res(); } else nettoyage = res;
  } catch (e) {
    racine.replaceChildren(bandeau('erreur', 'Une erreur inattendue est survenue. Rechargez la page.'));
    console.error(e);
  }
  racine.focus({ preventScroll: true });
}

window.addEventListener('hashchange', rendre);
window.addEventListener('session-expiree', () => { aller('/connexion'); rendre(); });
boutonSortie.addEventListener('click', () => { ecrireJeton(null); aller('/connexion'); });

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js', { scope: './' }).catch(() => { /* hors HTTPS ou refusé : l'interface marche sans */ });
}
rendre();
