import { get, post, put, del, ecrireJeton } from '../api.js';
import { h, fmtRelatif, fmtDateHeure, toast, squelette, bandeau } from '../dom.js';
import { icone } from '../icones.js';
import { URL_ABONNEMENT } from '../config.js';
import { afficher, erreurVue } from './commun.js';
import { sectionBalade } from './compte-balade.js';

const nouveaux = (n) => `${n} nouveau${n > 1 ? 'x' : ''} trajet${n > 1 ? 's' : ''}`;
const supprimes = (n) => `${n} trajet${n > 1 ? 's' : ''} supprimé${n > 1 ? 's' : ''}`;
const trajets = (n) => `${n} trajet${n > 1 ? 's' : ''}`;
/** Cause de la dernière synchronisation ratée, d'après le code rendu par l'API. */
const ERREURS_SYNCHRO = {
  auth: 'GeoRide a refusé la connexion.',
  debit: 'GeoRide limitait le débit.',
  reseau: 'GeoRide était injoignable.',
  autre: 'GeoRide a répondu de façon inattendue.',
};

// ---------- Compte : GeoRide, session ----------
export async function vueCompte(racine, { aller }) {
  const etatGeoride = h('span', { class: 'etat' }, 'Non lié');
  const contenu = h('div', {}, squelette('ligne'), squelette('ligne'));
  let minuteur = null;
  const arreterMinuteur = () => { if (minuteur) { clearInterval(minuteur); minuteur = null; } };
  const sortie = h('button', { class: 'btn plein', type: 'button' }, icone('sortie'), 'Se déconnecter');
  sortie.addEventListener('click', () => { ecrireJeton(null); aller('/connexion'); });

  afficher(racine,
    h('div', { class: 'titre-page' }, h('h1', {}, 'Compte'), h('p', {}, 'Reliez votre moto GeoRide pour retrouver vos trajets sur la carte.')),
    h('section', { class: 'bloc', 'aria-labelledby': 'h-georide' }, h('div', { class: 'entete-bloc' }, h('h2', { id: 'h-georide' }, 'GeoRide'), etatGeoride), contenu),
    h('section', { class: 'bloc', 'aria-labelledby': 'h-balade' }, h('div', { class: 'entete-bloc' }, h('h2', { id: 'h-balade' }, 'Balade')), sectionBalade()),
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
    const aRelier = etat.lie && etat.session === 'a_relier';
    etatGeoride.textContent = !etat.lie ? 'Non lié' : (aRelier ? 'À reconnecter' : 'Lié');
    etatGeoride.classList.toggle('ok', Boolean(etat.lie) && !aRelier);
    etatGeoride.classList.toggle('alerte', aRelier);
    contenu.replaceChildren(etat.lie ? vueLie(etat) : vueLiaison());
  }

  // Étape 1 : lier (ou reconnecter : e-mail prérempli, motos et trajets conservés)
  function formulaireLiaison({ email: emailInitial = '', reconnexion = false } = {}) {
    const email = h('input', { type: 'email', id: 'g-email', required: true, autocomplete: 'username', inputmode: 'email', autocapitalize: 'off', spellcheck: 'false', value: emailInitial });
    const mdp = h('input', { type: 'password', id: 'g-mdp', required: true, autocomplete: 'current-password' });
    const voir = h('button', { class: 'lien', type: 'button', 'aria-controls': 'g-mdp', 'aria-pressed': 'false' }, 'Afficher le mot de passe');
    voir.addEventListener('click', () => {
      const visible = mdp.type === 'password';
      mdp.type = visible ? 'text' : 'password';
      voir.textContent = visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe';
      voir.setAttribute('aria-pressed', String(visible));
    });
    const depuis = reconnexion ? null : h('input', { type: 'date', id: 'g-depuis' });
    const libelle = reconnexion ? 'Reconnecter' : 'Lier mon compte';
    const bouton = h('button', { class: 'btn primaire plein', type: 'submit' }, icone('lien'), libelle);
    const form = h('form', {},
      h('p', { class: 'muted petit' }, reconnexion
        ? 'GeoRide ne reconnaît plus la connexion de RuneRide (accès expiré ou refusé). Saisissez à nouveau votre mot de passe : vos motos et vos trajets importés sont conservés.'
        : 'Vos identifiants servent uniquement à récupérer vos trajets. Votre mot de passe n\'est pas conservé : seul un jeton d\'accès GeoRide l\'est, chiffré.'),
      h('label', { class: 'champ' }, h('span', {}, 'E-mail GeoRide'), email),
      h('label', { class: 'champ' }, h('span', {}, 'Mot de passe GeoRide'), mdp, voir),
      depuis ? h('label', { class: 'champ' }, h('span', {}, 'Importer à partir du'), depuis, h('span', { class: 'aide' }, 'Facultatif : sans date, les 30 derniers jours.')) : null,
      bouton);
    form.addEventListener('submit', (ev) => {
      ev.preventDefault();
      occupe([bouton], async () => {
        bouton.lastChild.textContent = 'Vérification auprès de GeoRide…';
        try {
          const corps = { email: email.value.trim(), mot_de_passe: mdp.value };
          if (depuis?.value) corps.suivi_depuis = depuis.value;
          await put('/api/georide', corps);
          mdp.value = '';
          let avertissement = null;
          try { await get('/api/georide/motos'); } catch (e2) { if (e2.status === 401) throw e2; avertissement = e2.message; } // liste des motos juste après la liaison
          if (reconnexion) toast('ok', 'Compte reconnecté. Vos motos et vos trajets sont conservés.');
          else if (avertissement) toast('info', 'Compte lié, mais la liste des motos n\'a pas pu être chargée : utilisez « Actualiser la liste ».');
          else toast('ok', 'Compte GeoRide lié. Choisissez maintenant vos motos.');
          await rafraichir();
        } finally { bouton.lastChild.textContent = libelle; }
      });
    });
    return form;
  }

  function vueLiaison() {
    return h('ol', { class: 'etapes' },
      etape('actuelle', '1', 'Lier votre compte', formulaireLiaison()),
      etape('a-venir', '2', 'Choisir vos motos', h('p', { class: 'muted petit' }, 'Vous sélectionnez celles dont vous voulez retrouver les trajets.')),
      etape('a-venir', '3', 'Importer vos trajets', h('p', { class: 'muted petit' }, 'Première synchronisation, puis mise à jour à la demande.')));
  }

  // Résumé de la dernière synchronisation (date, trajets nouveaux sur vus, cause d'un échec éventuel).
  function dernierResume(etat) {
    const quand = etat.derniere_synchro
      ? `Dernière synchronisation : ${fmtRelatif(etat.derniere_synchro)} (${fmtDateHeure(etat.derniere_synchro)})${etat.derniere_synchro_total === null ? '.' : ` : ${nouveaux(etat.derniere_synchro_nouveaux)} sur ${etat.derniere_synchro_total}.`}`
      : 'Pas encore synchronisé.';
    return [
      h('p', { class: 'muted petit' }, quand),
      etat.derniere_synchro_erreur ? bandeau('erreur', `La dernière tentative a échoué : ${ERREURS_SYNCHRO[etat.derniere_synchro_erreur] ?? ERREURS_SYNCHRO.autre} Réessayez dans un moment.`) : null,
    ];
  }

  /** Rapport affiché après une synchronisation : total, puis une ligne par moto. */
  function rapport(r) {
    const titre = r.nouveaux > 0
      ? `${nouveaux(r.nouveaux)} importé${r.nouveaux > 1 ? 's' : ''}, ${trajets(r.total)} vu${r.total > 1 ? 's' : ''} chez GeoRide.`
      : `Rien de nouveau : ${trajets(r.total)} vu${r.total > 1 ? 's' : ''} chez GeoRide, déjà tous importés.`;
    return h('div', { class: 'rapport' },
      h('p', {}, h('strong', {}, 'Synchronisation terminée. '), titre),
      r.motos?.length ? h('ul', {}, r.motos.map((m) => h('li', {}, h('strong', {}, m.name || `Moto ${m.tracker_id}`), h('span', { class: 'muted' }, ` : ${nouveaux(m.nouveaux)} sur ${m.total}`)))) : null,
      h('a', { class: 'btn plein', href: '#/trajets' }, icone('route'), 'Voir mes trajets'));
  }

  // Étapes 2 et 3 : motos, synchronisation ; ou reconnexion si GeoRide a refusé le jeton
  function vueLie(etat) {
    const aRelier = etat.session === 'a_relier';
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
      // Le serveur ne publie pas de progression : barre indéterminée et temps écoulé.
      const debut = Date.now();
      const duree = h('span', { class: 'muted' }, '0 s');
      resultat.replaceChildren(h('div', { class: 'attente', role: 'status' },
        h('p', {}, h('strong', {}, 'Synchronisation en cours… '), duree),
        h('p', { class: 'muted petit' }, 'Les trajets et leurs tracés sont récupérés un par un : cela peut prendre une minute.'),
        h('progress', { 'aria-label': 'Synchronisation en cours' })));
      minuteur = setInterval(() => { duree.textContent = `${Math.round((Date.now() - debut) / 1000)} s`; }, 1000);
      let r;
      try {
        r = await post('/api/georide/synchronisation');
      } catch (e) {
        resultat.replaceChildren();
        await rafraichir(); // l'état affiché suit le serveur (par exemple « à reconnecter »)
        throw e;
      } finally { arreterMinuteur(); }
      await rafraichir();
      document.getElementById('rapport-synchro')?.replaceChildren(rapport(r));
    }));
    delier.addEventListener('click', () => { if (confirm('Délier votre compte GeoRide ? Le jeton mémorisé est supprimé ; vos trajets déjà importés sont conservés.')) occupe(tous, async () => { await del('/api/georide'); await rafraichir(); }); });
    reinit.addEventListener('click', () => { if (confirm('Supprimer tous vos trajets importés ? Une nouvelle synchronisation les réimportera.')) occupe(tous, async () => { const r = await del('/api/trajets'); toast('ok', `${supprimes(r.supprimes)}.`); await rafraichir(); }); });
    majSynchro();

    const zoneSensible = h('details', { class: 'danger' }, h('summary', {}, 'Zone sensible'), h('div', { class: 'contenu' }, delier, reinit));
    const compteLie = etape('faite', '✓', 'Compte lié', h('p', { class: 'muted' }, etat.email), h('p', { class: 'muted petit' }, `${trajets(etat.trajets_importes)} importé${etat.trajets_importes > 1 ? 's' : ''}.`));

    if (aRelier) {
      return h('div', {},
        h('ol', { class: 'etapes' },
          etape('actuelle', '1', 'Reconnecter votre compte', formulaireLiaison({ email: etat.email, reconnexion: true })),
          etape('faite', '✓', 'Vos motos et vos trajets', h('p', { class: 'muted petit' }, `Conservés : ${etat.motos.length ? etat.motos.map((m) => m.name || m.tracker_id).join(', ') : 'aucune moto'} · ${trajets(etat.trajets_importes)}.`))),
        zoneSensible);
    }
    return h('div', {},
      h('ol', { class: 'etapes' },
        compteLie,
        etape(aucune() ? 'actuelle' : 'faite', aucune() ? '2' : '✓', 'Vos motos',
          cases.length ? h('div', { class: 'motos' }, cases) : h('p', { class: 'muted' }, 'Aucune moto connue. Actualisez la liste.'), actualiser),
        etape(aucune() ? 'a-venir' : 'actuelle', '3', 'Importer vos trajets', dernierResume(etat), synchro, resultat, h('div', { id: 'rapport-synchro' }))),
      zoneSensible);
  }

  await rafraichir();
  return arreterMinuteur;
}

function etape(classe, num, titre, ...corps) {
  return h('li', { class: `etape ${classe}` }, h('span', { class: 'num', 'aria-hidden': 'true' }, num), h('div', { class: 'corps' }, h('h3', {}, titre), ...corps));
}
