import { get, post, put, del, ecrireJeton } from '../api.js';
import { h, fmtRelatif, toast, squelette, bandeau } from '../dom.js';
import { icone } from '../icones.js';
import { URL_ABONNEMENT } from '../config.js';
import { afficher, erreurVue } from './commun.js';

const nouveaux = (n) => `${n} nouveau${n > 1 ? 'x' : ''} trajet${n > 1 ? 's' : ''}`;
const supprimes = (n) => `${n} trajet${n > 1 ? 's' : ''} supprimé${n > 1 ? 's' : ''}`;

// ---------- Compte : GeoRide, session ----------
export async function vueCompte(racine, { aller }) {
  const etatGeoride = h('span', { class: 'etat' }, 'Non lié');
  const contenu = h('div', {}, squelette('ligne'), squelette('ligne'));
  const sortie = h('button', { class: 'btn plein', type: 'button' }, icone('sortie'), 'Se déconnecter');
  sortie.addEventListener('click', () => { ecrireJeton(null); aller('/connexion'); });

  afficher(racine,
    h('div', { class: 'titre-page' }, h('h1', {}, 'Compte'), h('p', {}, 'Reliez votre moto GeoRide pour retrouver vos trajets sur la carte.')),
    h('section', { class: 'bloc', 'aria-labelledby': 'h-georide' }, h('div', { class: 'entete-bloc' }, h('h2', { id: 'h-georide' }, 'GeoRide'), etatGeoride), contenu),
    h('section', { class: 'bloc', 'aria-labelledby': 'h-session' }, h('h2', { id: 'h-session' }, 'Session'),
      h('p', { class: 'muted' }, 'Gratuit : historique récent. ', h('a', { href: URL_ABONNEMENT }, 'Voir les abonnements')),
      sortie));

  // Une action à la fois : les boutons concernés sont désactivés pendant l'appel, l'erreur sort en message bref.
  const occupe = async (boutons, fn) => {
    boutons.forEach((b) => { b.disabled = true; });
    try { await fn(); } catch (e) { if (e.status !== 401) toast('erreur', e.message); }
    boutons.forEach((b) => { b.disabled = false; });
  };

  async function rafraichir() {
    let etat;
    try { etat = await get('/api/georide'); } catch (e) { contenu.replaceChildren(erreurVue(e) || ''); return; }
    etatGeoride.textContent = etat.lie ? 'Lié' : 'Non lié';
    etatGeoride.classList.toggle('ok', Boolean(etat.lie));
    contenu.replaceChildren(etat.lie ? vueLie(etat) : vueLiaison());
  }

  // Étape 1 : lier
  function vueLiaison() {
    const email = h('input', { type: 'email', id: 'g-email', required: true, autocomplete: 'username', inputmode: 'email', autocapitalize: 'off' });
    const mdp = h('input', { type: 'password', id: 'g-mdp', required: true, autocomplete: 'current-password' });
    const depuis = h('input', { type: 'date', id: 'g-depuis' });
    const bouton = h('button', { class: 'btn primaire plein', type: 'submit' }, icone('lien'), 'Lier mon compte');
    const form = h('form', {},
      h('p', { class: 'muted petit' }, 'Vos identifiants servent uniquement à récupérer vos trajets ; le mot de passe est conservé chiffré.'),
      h('label', { class: 'champ' }, h('span', {}, 'E-mail GeoRide'), email),
      h('label', { class: 'champ' }, h('span', {}, 'Mot de passe GeoRide'), mdp),
      h('label', { class: 'champ' }, h('span', {}, 'Importer à partir du'), depuis, h('span', { class: 'aide' }, 'Facultatif : sinon tout l\'historique disponible.')),
      bouton);
    form.addEventListener('submit', (ev) => {
      ev.preventDefault();
      occupe([bouton], async () => {
        const corps = { email: email.value.trim(), mot_de_passe: mdp.value };
        if (depuis.value) corps.suivi_depuis = depuis.value;
        await put('/api/georide', corps);
        await get('/api/georide/motos'); // récupère la liste des motos juste après la liaison
        toast('ok', 'Compte GeoRide lié. Choisissez maintenant vos motos.');
        await rafraichir();
      });
    });
    return h('ol', { class: 'etapes' },
      etape('actuelle', '1', 'Lier votre compte', form),
      etape('a-venir', '2', 'Choisir vos motos', h('p', { class: 'muted petit' }, 'Vous sélectionnez celles dont vous voulez retrouver les trajets.')),
      etape('a-venir', '3', 'Importer vos trajets', h('p', { class: 'muted petit' }, 'Première synchronisation, puis mise à jour à la demande.')));
  }

  // Étapes 2 et 3 : motos, synchronisation
  function vueLie(etat) {
    const cases = etat.motos.map((m) => h('label', { class: 'moto' }, h('input', { type: 'checkbox', value: m.tracker_id, checked: m.selected }), h('span', {}, m.name || `Moto ${m.tracker_id}`)));
    const choisies = () => cases.map((l) => l.querySelector('input')).filter((i) => i.checked).map((i) => i.value);
    const synchro = h('button', { class: 'btn primaire plein', type: 'button' }, icone('actualiser'), 'Synchroniser maintenant');
    const actualiser = h('button', { class: 'btn', type: 'button' }, icone('actualiser'), 'Actualiser la liste');
    const delier = h('button', { class: 'btn danger', type: 'button' }, 'Délier GeoRide');
    const reinit = h('button', { class: 'btn danger', type: 'button' }, 'Supprimer mes trajets importés');
    const resultat = h('div', { 'aria-live': 'polite' });
    const tous = [synchro, actualiser, delier, reinit];
    const aucune = () => choisies().length === 0;
    const majSynchro = () => { synchro.disabled = aucune(); };

    for (const c of cases) {
      c.querySelector('input').addEventListener('change', () => {
        majSynchro();
        put('/api/georide/motos', { motos: choisies() }).then(() => toast('ok', 'Sélection enregistrée.')).catch((e) => { if (e.status !== 401) toast('erreur', e.message); });
      });
    }
    actualiser.addEventListener('click', () => occupe(tous, async () => { await get('/api/georide/motos'); await rafraichir(); }));
    synchro.addEventListener('click', () => occupe(tous, async () => {
      await put('/api/georide/motos', { motos: choisies() });
      const r = await post('/api/georide/synchronisation');
      toast('ok', `Synchronisation terminée : ${nouveaux(r.nouveaux)}, ${r.total} au total.`);
      resultat.replaceChildren(h('a', { class: 'btn plein', href: '#/trajets', style: 'margin-top:10px' }, icone('route'), 'Voir mes trajets'));
      await rafraichir();
    }));
    delier.addEventListener('click', () => { if (confirm('Délier votre compte GeoRide ? Vos trajets déjà importés sont conservés.')) occupe(tous, async () => { await del('/api/georide'); await rafraichir(); }); });
    reinit.addEventListener('click', () => { if (confirm('Supprimer tous vos trajets importés ? Une nouvelle synchronisation les réimportera.')) occupe(tous, async () => { const r = await del('/api/trajets'); toast('ok', `${supprimes(r.supprimes)}.`); }); });
    majSynchro();

    return h('div', {},
      h('ol', { class: 'etapes' },
        etape('faite', '✓', 'Compte lié', h('p', { class: 'muted' }, etat.email)),
        etape(aucune() ? 'actuelle' : 'faite', aucune() ? '2' : '✓', 'Vos motos',
          cases.length ? h('div', { class: 'motos' }, cases) : h('p', { class: 'muted' }, 'Aucune moto connue. Actualisez la liste.'), actualiser),
        etape(aucune() ? 'a-venir' : 'actuelle', '3', 'Importer vos trajets',
          h('p', { class: 'muted petit' }, etat.derniere_synchro ? `Dernière synchronisation : ${fmtRelatif(etat.derniere_synchro)}.` : 'Pas encore synchronisé.'),
          synchro, resultat)),
      h('details', { class: 'danger' }, h('summary', {}, 'Zone sensible'), h('div', { class: 'contenu' }, delier, reinit)));
  }

  await rafraichir();
}

function etape(classe, num, titre, ...corps) {
  return h('li', { class: `etape ${classe}` }, h('span', { class: 'num', 'aria-hidden': 'true' }, num), h('div', { class: 'corps' }, h('h3', {}, titre), ...corps));
}
