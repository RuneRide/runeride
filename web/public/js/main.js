import { lireJeton, ecrireJeton } from './api.js';
import { vueConnexion } from './vues/connexion.js';
import { vueTrajets } from './vues/trajets.js';
import { vueRavitaillement } from './vues/pitstop.js';
import { vueCompte } from './vues/compte.js';
import { bandeau, squelette } from './dom.js';

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

const TITRES = { connexion: 'connexion', trajets: 'mes trajets', trajet: 'trajet', compte: 'compte', ravitaillement: 'pit-stop' };
const PLEIN_ECRAN = new Set(['trajets', 'trajet', 'ravitaillement', 'connexion']);

async function rendre() {
  const { segments, params } = analyser();
  // Retour de « Continuer avec Google » : le serveur a mis le jeton dans le fragment.
  if (segments[0] === 'google') {
    const jeton = params.get('jeton');
    if (jeton) ecrireJeton(jeton);
    // replace : le jeton ne reste pas dans l'historique du navigateur
    window.location.replace(`#${jeton ? '/trajets' : '/connexion?erreur=google'}`);
    return;
  }
  const connecte = Boolean(lireJeton());
  nav.hidden = boutonSortie.hidden = !connecte;
  let [route, arg] = segments;
  if (route === 'georide') route = 'compte'; // ancienne adresse

  if (typeof nettoyage === 'function') { try { nettoyage(); } catch { /* carte déjà détruite */ } }
  nettoyage = null;
  const moi = ++jetonRendu;

  const publique = route === 'connexion';
  if (!connecte && !publique) { aller(params.get('inscription') ? '/connexion?inscription=1' : '/connexion'); return; }
  if (connecte && publique) { aller('/trajets'); return; }

  nav.querySelectorAll('a').forEach((a) => {
    const actif = a.dataset.route === route || (route === 'trajet' && a.dataset.route === 'trajets');
    a.classList.toggle('actif', actif);
    if (actif) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
  });
  const ctx = { aller, params, id: arg };
  let vue;
  switch (route) {
    case 'connexion': vue = vueConnexion; break;
    case 'trajets': case 'trajet': vue = vueTrajets; break;
    case 'compte': vue = vueCompte; break;
    case 'ravitaillement': vue = vueRavitaillement; break;
    default: aller('/trajets'); return;
  }
  document.title = `RuneRide — ${TITRES[route]}`;
  racine.classList.toggle('plein', PLEIN_ECRAN.has(route));
  racine.replaceChildren(squelette('l'));
  try {
    const res = await vue(racine, ctx);
    if (moi !== jetonRendu) { if (typeof res === 'function') res(); } else nettoyage = res;
  } catch (e) {
    racine.replaceChildren(bandeau('erreur', 'Une erreur inattendue est survenue. Rechargez la page.'));
    console.error(e);
  }
  if (moi === jetonRendu) racine.focus({ preventScroll: true });
}

window.addEventListener('hashchange', rendre);
window.addEventListener('session-expiree', () => { aller('/connexion'); rendre(); });
boutonSortie.addEventListener('click', () => { ecrireJeton(null); aller('/connexion'); });

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js', { scope: './' }).catch(() => { /* hors HTTPS ou refusé : l'interface marche sans */ });
}
rendre();
