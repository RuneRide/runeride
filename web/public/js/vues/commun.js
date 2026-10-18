import { ApiError } from '../api.js';
import { h, bandeau } from '../dom.js';
import { URL_ABONNEMENT } from '../config.js';

export const afficher = (racine, ...noeuds) => racine.replaceChildren(...noeuds.flat().filter((n) => n !== null && n !== undefined && n !== false));

export const invitationAbonnement = (texte) =>
  bandeau('info', h('strong', {}, 'Réservé aux abonnés. '), texte, ' ', h('a', { href: URL_ABONNEMENT }, 'Voir les abonnements'));

/** Erreur à afficher (rien pour un 401 : l'interface revient d'elle-même à la connexion). */
export const erreurVue = (e) => (e instanceof ApiError && e.status === 401 ? null : bandeau('erreur', e.message));

const mobile = () => window.matchMedia('(max-width: 759px)').matches;
const ETATS = ['reduit', 'moyen', 'haut'];

/**
 * Écran « carte + panneau » : panneau à gauche sur ordinateur, feuille glissante en bas sur mobile
 * (3 positions : réduit, moyen, haut ; la poignée se touche ou se glisse).
 * Rend { ecran, zoneCarte, corps, definirEtat, etat, marge, consigne }.
 */
export function ecranCarte({ etat: etatInitial = 'moyen', titrePanneau = 'Panneau', ratioMoyen = 0.46 } = {}) {
  let etat = etatInitial;
  let glisse = false;
  const zoneCarte = h('div', { class: 'carte-pleine', role: 'region', 'aria-label': 'Carte' });
  const consigne = h('div', { class: 'consigne', hidden: true, role: 'status' });
  const poignee = h('button', { class: 'poignee', type: 'button', 'aria-label': `Agrandir ou réduire : ${titrePanneau}` });
  const corps = h('div', { class: 'panneau-corps' });
  const panneau = h('section', { class: 'panneau', 'aria-label': titrePanneau }, poignee, corps);
  const ecran = h('div', { class: 'ecran', 'data-etat': etat, style: `--moyen: ${Math.round(ratioMoyen * 100)}%` }, zoneCarte, consigne, panneau);

  const definirEtat = (e) => { etat = e; ecran.dataset.etat = e; poignee.setAttribute('aria-expanded', String(e !== 'reduit')); };
  definirEtat(etat);

  let y0 = null;
  poignee.addEventListener('pointerdown', (ev) => { y0 = ev.clientY; glisse = false; poignee.setPointerCapture(ev.pointerId); });
  poignee.addEventListener('pointerup', (ev) => {
    if (y0 === null) return;
    const dy = ev.clientY - y0; y0 = null;
    if (Math.abs(dy) < 28) return; // simple appui : traité par « click »
    glisse = true;
    const i = ETATS.indexOf(etat);
    definirEtat(ETATS[Math.max(0, Math.min(ETATS.length - 1, i + (dy < 0 ? 1 : -1)))]);
  });
  poignee.addEventListener('click', () => {
    if (glisse) { glisse = false; return; }
    definirEtat(etat === 'reduit' ? 'moyen' : 'reduit');
  });

  /** Espace de la carte masqué par la feuille (mobile), pour que les tracés restent visibles au-dessus. */
  const marge = () => {
    if (!mobile()) return {};
    if (etat === 'reduit') return { bas: 148 };
    if (etat === 'moyen') return { bas: Math.round(ecran.clientHeight * ratioMoyen) };
    return {};
  };
  return { ecran, zoneCarte, corps, definirEtat, etat: () => etat, marge, consigne };
}
