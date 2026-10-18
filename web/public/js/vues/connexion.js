import { post, ecrireJeton } from '../api.js';
import { h, bandeau } from '../dom.js';
import { icone } from '../icones.js';
import { afficher } from './commun.js';

// ---------- Connexion / inscription ----------
export function vueConnexion(racine, { aller, params }) {
  let inscription = params.get('inscription') === '1';
  const msg = h('div', { 'aria-live': 'polite' });
  const email = h('input', { type: 'email', id: 'email', name: 'email', autocomplete: 'email', inputmode: 'email', required: true, autocapitalize: 'off', spellcheck: 'false' });
  const mdp = h('input', { type: 'password', id: 'mdp', name: 'mot_de_passe', required: true });
  const oeil = h('button', { type: 'button', 'aria-label': 'Afficher le mot de passe', 'aria-pressed': 'false' }, icone('oeil'));
  const aide = h('span', { class: 'aide' });
  const bouton = h('button', { class: 'btn primaire plein', type: 'submit' });
  const ongletConnexion = h('button', { type: 'button', role: 'tab' }, 'Connexion');
  const ongletInscription = h('button', { type: 'button', role: 'tab' }, 'Inscription');
  const bascule = h('div', { class: 'bascule', role: 'tablist', 'aria-label': 'Connexion ou inscription' }, ongletConnexion, ongletInscription);

  const maj = () => {
    ongletConnexion.setAttribute('aria-selected', String(!inscription));
    ongletInscription.setAttribute('aria-selected', String(inscription));
    bouton.textContent = inscription ? 'Créer mon compte' : 'Se connecter';
    mdp.autocomplete = inscription ? 'new-password' : 'current-password';
    mdp.minLength = inscription ? 10 : 0;
    aide.textContent = inscription ? '10 caractères minimum.' : '';
    msg.replaceChildren();
  };
  ongletConnexion.addEventListener('click', () => { inscription = false; maj(); });
  ongletInscription.addEventListener('click', () => { inscription = true; maj(); });
  oeil.addEventListener('click', () => {
    const visible = mdp.type === 'password';
    mdp.type = visible ? 'text' : 'password';
    oeil.setAttribute('aria-pressed', String(visible));
    oeil.setAttribute('aria-label', visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
    oeil.replaceChildren(icone(visible ? 'oeilBarre' : 'oeil'));
  });

  const form = h('form', { class: 'carte-connexion', novalidate: true },
    bascule,
    h('label', { class: 'champ' }, h('span', {}, 'Adresse e-mail'), email),
    h('label', { class: 'champ' }, h('span', {}, 'Mot de passe'), h('div', { class: 'champ-mdp' }, mdp, oeil), aide),
    msg, bouton);
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    msg.replaceChildren();
    if (!email.value.includes('@') || mdp.value === '') { msg.append(bandeau('erreur', 'Saisissez votre adresse e-mail et votre mot de passe.')); (email.value.includes('@') ? mdp : email).focus(); return; }
    if (inscription && mdp.value.length < 10) { msg.append(bandeau('erreur', 'Le mot de passe doit faire au moins 10 caractères.')); mdp.focus(); return; }
    bouton.disabled = true;
    bouton.textContent = inscription ? 'Création…' : 'Connexion…';
    try {
      const r = await post(inscription ? '/api/inscription' : '/api/connexion', { email: email.value.trim(), mot_de_passe: mdp.value }, { avecJeton: false });
      ecrireJeton(r.jeton);
      aller('/trajets');
    } catch (e) {
      msg.append(bandeau('erreur', e.status === 409 ? 'Un compte existe déjà avec cette adresse. Connectez-vous.' : e.message));
      bouton.disabled = false;
      bouton.textContent = inscription ? 'Créer mon compte' : 'Se connecter';
    }
  });
  maj();
  afficher(racine, h('div', { class: 'accueil' },
    h('img', { class: 'logo', src: 'brand/monogram.svg', alt: '', width: 56, height: 70 }),
    h('h1', {}, 'Rune', h('span', { style: 'color: var(--accent)' }, 'Ride')),
    h('p', { class: 'promesse' }, 'Vos trajets à moto sur une carte, et le carburant le moins cher sur votre route.'),
    form));
  email.focus({ preventScroll: true });
}
