import { get, post, put, del, ecrireJeton, ApiError } from './api.js';
import { h, bandeau, fmtDateHeure, fmtDate, fmtRelatif, fmtKm, fmtDuree, fmtEuro } from './dom.js';
import { creerCarte, dessinerTrajets, marqueur } from './carte.js';
import { URL_ABONNEMENT } from './config.js';

const afficher = (racine, ...noeuds) => racine.replaceChildren(...noeuds.flat().filter((n) => n !== null && n !== undefined && n !== false));

/** Chaque vue reçoit le conteneur et le contexte { aller, params } ; elle peut rendre une fonction de nettoyage. */

export const invitationAbonnement = (texte) =>
  bandeau('info', h('strong', {}, 'Réservé aux abonnés. '), texte, ' ', h('a', { href: URL_ABONNEMENT }, 'Voir les abonnements'));

const erreurVue = (e) => (e instanceof ApiError && e.status === 401 ? null : bandeau('erreur', e.message));
const chargement = () => h('p', { class: 'muted' }, 'Chargement…');

// ---------- Connexion / inscription ----------
export function vueConnexion(racine, { aller, params }) {
  let inscription = params.get('inscription') === '1';
  const msg = h('div');
  const email = h('input', { type: 'email', id: 'email', name: 'email', autocomplete: 'email', required: true });
  const mdp = h('input', { type: 'password', id: 'mdp', name: 'mot_de_passe', required: true, minlength: '10' });
  const titre = h('h1');
  const bouton = h('button', { class: 'btn primaire', type: 'submit' });
  const bascule = h('button', { class: 'lien', type: 'button' });
  const aide = h('p', { class: 'muted petit' });

  const maj = () => {
    titre.textContent = inscription ? 'Créer mon compte' : 'Se connecter';
    bouton.textContent = inscription ? 'Créer mon compte' : 'Se connecter';
    bascule.textContent = inscription ? 'J\'ai déjà un compte' : 'Pas encore de compte ? S\'inscrire';
    mdp.autocomplete = inscription ? 'new-password' : 'current-password';
    aide.textContent = inscription ? 'Mot de passe : 10 caractères minimum.' : '';
  };
  bascule.addEventListener('click', () => { inscription = !inscription; msg.replaceChildren(); maj(); });

  const form = h('form', { class: 'carte formulaire', novalidate: true },
    h('label', { for: 'email' }, 'Adresse e-mail'), email,
    h('label', { for: 'mdp' }, 'Mot de passe'), mdp, aide,
    msg, bouton, bascule);
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    msg.replaceChildren();
    if (!email.value.includes('@') || mdp.value === '') { msg.append(bandeau('erreur', 'Saisissez votre adresse e-mail et votre mot de passe.')); return; }
    if (inscription && mdp.value.length < 10) { msg.append(bandeau('erreur', 'Le mot de passe doit faire au moins 10 caractères.')); return; }
    bouton.disabled = true;
    try {
      const r = await post(inscription ? '/api/inscription' : '/api/connexion', { email: email.value.trim(), mot_de_passe: mdp.value }, { avecJeton: false });
      ecrireJeton(r.jeton);
      aller('/trajets');
    } catch (e) {
      msg.append(bandeau('erreur', e.status === 409 ? 'Un compte existe déjà avec cette adresse. Connectez-vous.' : e.message));
      bouton.disabled = false;
    }
  });
  maj();
  const google = h('a', { class: 'btn', href: '/api/google' }, 'Continuer avec Google');
  const erreurGoogle = params.get('erreur') === 'google' ? bandeau('erreur', 'La connexion avec Google a échoué. Réessayez, ou utilisez votre adresse e-mail.') : null;
  afficher(racine, h('div', { class: 'etroit' }, titre, erreurGoogle, google, form));
}

// ---------- Liste + carte des trajets ----------
export async function vueTrajets(racine, { aller }) {
  afficher(racine, h('h1', {}, 'Mes trajets'), chargement());
  let carteData; let liste;
  try { [carteData, liste] = await Promise.all([get('/api/trajets/carte', { limite: 100, points: 200 }), get('/api/trajets', { limite: 100 })]); }
  catch (e) { afficher(racine, h('h1', {}, 'Mes trajets'), erreurVue(e)); return; }

  const zoneCarte = h('div', { id: 'carte', class: 'carte-zone', role: 'region', 'aria-label': 'Carte des trajets' });
  const ul = h('ul', { class: 'liste' });
  const vide = liste.trajets.length === 0;
  afficher(racine, 
    h('h1', {}, 'Mes trajets'),
    liste.historique_limite ? bandeau('info', `Historique gratuit limité : trajets depuis le ${fmtDate(liste.depuis)}. `, h('a', { href: URL_ABONNEMENT }, 'Les abonnés gardent tout l\'historique.')) : null,
    vide ? bandeau('info', 'Aucun trajet pour le moment. ', h('a', { href: '#/georide' }, 'Liez votre compte GeoRide'), ' puis lancez une synchronisation.') : null,
    zoneCarte,
    vide ? null : h('p', { class: 'muted petit' }, `${liste.total} trajet${liste.total > 1 ? 's' : ''}`),
    ul);

  const carte = creerCarte(zoneCarte);
  let dessin = null;
  if (carte) dessin = dessinerTrajets(carte, carteData.trajets, { onClic: (id) => aller(`/trajet/${id}`) });
  const items = new Map();
  for (const t of liste.trajets) {
    const li = h('li', {},
      h('a', { href: `#/trajet/${t.id}`, class: 'ligne-trajet' },
        h('strong', {}, t.titre || 'Trajet'),
        h('span', { class: 'muted' }, `${fmtDateHeure(t.debut)} · ${fmtKm(t.distance_km)} · ${fmtDuree(t.duree_min)}`)));
    if (dessin) {
      li.addEventListener('mouseenter', () => dessin.activer(t.id));
      li.addEventListener('mouseleave', () => { dessin.activer(null); dessin.ajuster(); });
    }
    items.set(t.id, li);
    ul.append(li);
  }
  return () => carte?.remove();
}

// ---------- Détail d'un trajet ----------
export async function vueTrajet(racine, { id }) {
  const retour = h('a', { href: '#/trajets', class: 'retour' }, '← Mes trajets');
  afficher(racine, retour, chargement());
  let t;
  try { t = await get('/api/trajet', { id }); }
  catch (e) {
    afficher(racine, retour, e.abonnementRequis ? invitationAbonnement('Ce trajet date d\'avant la période d\'historique gratuit.') : erreurVue(e));
    return;
  }
  const zoneCarte = h('div', { class: 'carte-zone', role: 'region', 'aria-label': 'Tracé du trajet' });
  afficher(racine, retour, h('h1', {}, t.titre || 'Trajet'),
    h('dl', { class: 'stats' },
      h('div', {}, h('dt', {}, 'Départ'), h('dd', {}, fmtDateHeure(t.debut))),
      h('div', {}, h('dt', {}, 'Arrivée'), h('dd', {}, fmtDateHeure(t.fin))),
      h('div', {}, h('dt', {}, 'Distance'), h('dd', {}, fmtKm(t.distance_km))),
      h('div', {}, h('dt', {}, 'Durée'), h('dd', {}, fmtDuree(t.duree_min))),
      h('div', {}, h('dt', {}, 'Points GPS'), h('dd', {}, t.points))),
    zoneCarte);
  const carte = creerCarte(zoneCarte);
  if (carte) {
    const d = dessinerTrajets(carte, [{ id: t.id, titre: t.titre, trace: t.trace }]);
    d.activer(t.id);
    if (t.trace.length) {
      marqueur(carte, t.trace[0], 'Départ', '#10b981');
      marqueur(carte, t.trace[t.trace.length - 1], 'Arrivée', '#ef4444');
    }
  }
  return () => carte?.remove();
}

// ---------- GeoRide ----------
const ERREURS_SYNCHRO = {
  auth: 'GeoRide a refusé la connexion.',
  debit: 'GeoRide limitait le débit.',
  reseau: 'GeoRide était injoignable.',
  autre: 'GeoRide a répondu de façon inattendue.',
};
const pluriel = (n, un, plusieurs) => `${n} ${n > 1 ? plusieurs : un}`;

/** Parcours en trois étapes : relier le compte, choisir les motos, synchroniser. */
function etapes(actuelle) {
  const noms = ['Relier le compte', 'Choisir les motos', 'Synchroniser'];
  return h('ol', { class: 'etapes', 'aria-label': 'Étapes' }, noms.map((nom, i) =>
    h('li', { class: i + 1 < actuelle ? 'fait' : (i + 1 === actuelle ? 'actif' : null), 'aria-current': i + 1 === actuelle ? 'step' : null }, nom)));
}

function resumeSynchro(r) {
  const titre = r.nouveaux > 0
    ? `${pluriel(r.nouveaux, 'nouveau trajet importé', 'nouveaux trajets importés')}, ${pluriel(r.total, 'trajet vu', 'trajets vus')} chez GeoRide.`
    : `Rien de nouveau : ${pluriel(r.total, 'trajet vu', 'trajets vus')} chez GeoRide, déjà tous importés.`;
  return h('div', { class: 'carte' },
    h('h2', {}, 'Synchronisation terminée'),
    h('p', {}, titre),
    r.motos?.length ? h('ul', { class: 'liste' }, r.motos.map((m) =>
      h('li', { class: 'ligne-moto' }, h('strong', {}, m.name || `Moto ${m.tracker_id}`), h('span', { class: 'muted' }, ` : ${pluriel(m.nouveaux, 'nouveau', 'nouveaux')} sur ${m.total}`)))) : null,
    h('a', { class: 'btn', href: '#/trajets' }, 'Voir mes trajets'));
}

export async function vueGeoride(racine) {
  const msg = h('div', { 'aria-live': 'polite' });
  const resultat = h('div', { 'aria-live': 'polite' });
  const corps = h('div');
  afficher(racine, h('h1', {}, 'GeoRide'), msg, resultat, corps);
  const dire = (type, texte) => msg.replaceChildren(bandeau(type, texte));
  let minuteur = null;
  const arreterMinuteur = () => { if (minuteur) { clearInterval(minuteur); minuteur = null; } };

  async function rafraichir() {
    corps.replaceChildren(chargement());
    let etat;
    try { etat = await get('/api/georide'); } catch (e) { afficher(corps, erreurVue(e)); return; }
    corps.replaceChildren(etat.lie ? vueLie(etat) : h('div', {}, etapes(1), vueLiaison()));
  }

  /** Formulaire de liaison ; en reconnexion, l'e-mail est prérempli et motos et trajets sont conservés. */
  function vueLiaison({ email: emailInitial = '', reconnexion = false } = {}) {
    const email = h('input', { type: 'email', id: 'g-email', required: true, autocomplete: 'username', autocapitalize: 'off', spellcheck: 'false', value: emailInitial });
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
    const bouton = h('button', { class: 'btn primaire', type: 'submit' }, libelle);
    const form = h('form', { class: 'carte formulaire' },
      h('h2', {}, reconnexion ? 'Reconnecter GeoRide' : 'Relier votre compte GeoRide'),
      h('p', {}, reconnexion
        ? 'GeoRide ne reconnaît plus la connexion de RuneRide (accès expiré ou refusé). Saisissez à nouveau votre mot de passe : vos motos et vos trajets importés sont conservés.'
        : 'Saisissez vos identifiants GeoRide, ceux de l\'application GeoRide. Ils servent uniquement à récupérer vos trajets ; votre mot de passe n\'est pas conservé, seul un jeton d\'accès GeoRide l\'est (chiffré).'),
      h('label', { for: 'g-email' }, 'E-mail GeoRide'), email,
      h('label', { for: 'g-mdp' }, 'Mot de passe GeoRide'), mdp, voir,
      depuis ? [h('label', { for: 'g-depuis' }, 'Importer à partir du (facultatif)'), depuis,
        h('p', { class: 'muted petit' }, 'Sans date, RuneRide importe les 30 derniers jours.')] : null,
      bouton);
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      bouton.disabled = true; bouton.textContent = 'Vérification auprès de GeoRide…'; msg.replaceChildren();
      try {
        const corpsReq = { email: email.value.trim(), mot_de_passe: mdp.value };
        if (depuis?.value) corpsReq.suivi_depuis = depuis.value;
        await put('/api/georide', corpsReq);
        mdp.value = '';
        let avertissement = null;
        try { await get('/api/georide/motos'); } catch (e2) { if (e2.status === 401) throw e2; avertissement = e2.message; } // liste des motos juste après la liaison
        if (reconnexion) dire('ok', 'Compte reconnecté. Vos motos et vos trajets sont conservés.');
        else if (avertissement) dire('info', `Compte lié, mais la liste des motos n'a pas pu être chargée (${avertissement}) Utilisez « Actualiser la liste des motos ».`);
        else dire('ok', 'Compte GeoRide lié. Choisissez maintenant les motos à suivre.');
        await rafraichir();
      } catch (e) {
        if (e.status !== 401) dire('erreur', e.message);
        bouton.disabled = false; bouton.textContent = libelle; mdp.focus();
      }
    });
    return form;
  }

  function vueLie(etat) {
    const aRelier = etat.session === 'a_relier';
    const cases = etat.motos.map((m) => {
      const c = h('input', { type: 'checkbox', value: m.tracker_id, checked: m.selected, id: `m-${m.tracker_id}` });
      return h('label', { class: 'case', for: c.id }, c, ' ', m.name || `Moto ${m.tracker_id}`);
    });
    const choisies = () => cases.map((l) => l.querySelector('input')).filter((i) => i.checked).map((i) => i.value);
    const enregistrer = h('button', { class: 'btn', type: 'button' }, 'Enregistrer la sélection');
    const synchro = h('button', { class: 'btn primaire', type: 'button' }, 'Synchroniser maintenant');
    const actualiser = h('button', { class: 'btn', type: 'button' }, 'Actualiser la liste des motos');
    const delier = h('button', { class: 'btn danger', type: 'button' }, 'Délier GeoRide');
    const reinit = h('button', { class: 'btn danger', type: 'button' }, 'Supprimer mes trajets importés');
    const tous = [enregistrer, synchro, actualiser, delier, reinit];
    const majSynchro = () => { synchro.disabled = choisies().length === 0; };
    cases.forEach((l) => l.querySelector('input').addEventListener('change', majSynchro));
    majSynchro();

    const occupe = async (fn) => {
      tous.forEach((b) => { b.disabled = true; });
      msg.replaceChildren();
      try { await fn(); } catch (e) { if (e.status !== 401) dire('erreur', e.message); }
      tous.forEach((b) => { b.disabled = false; });
      majSynchro();
    };

    enregistrer.addEventListener('click', () => occupe(async () => { await put('/api/georide/motos', { motos: choisies() }); dire('ok', 'Sélection enregistrée.'); await rafraichir(); }));
    actualiser.addEventListener('click', () => occupe(async () => { await get('/api/georide/motos'); await rafraichir(); }));
    synchro.addEventListener('click', () => occupe(async () => {
      resultat.replaceChildren();
      await put('/api/georide/motos', { motos: choisies() });
      // Le serveur ne publie pas de progression : on montre une barre indéterminée et le temps écoulé.
      const debut = Date.now();
      const duree = h('span', { class: 'muted' }, '0 s');
      resultat.replaceChildren(bandeau('info',
        h('strong', {}, 'Synchronisation en cours… '), duree, h('br'),
        'Les trajets et leurs tracés sont récupérés un par un : cela peut prendre une minute.',
        h('progress', { 'aria-label': 'Synchronisation en cours' })));
      minuteur = setInterval(() => { duree.textContent = `${Math.round((Date.now() - debut) / 1000)} s`; }, 1000);
      try {
        resultat.replaceChildren(resumeSynchro(await post('/api/georide/synchronisation')));
      } catch (e) {
        resultat.replaceChildren();
        await rafraichir(); // l'état affiché suit le serveur (par exemple « à reconnecter »)
        throw e;
      } finally { arreterMinuteur(); }
      await rafraichir();
    }));
    delier.addEventListener('click', () => { if (confirm('Délier votre compte GeoRide ? Le jeton mémorisé est supprimé ; vos trajets déjà importés sont conservés.')) occupe(async () => { resultat.replaceChildren(); await del('/api/georide'); await rafraichir(); }); });
    reinit.addEventListener('click', () => { if (confirm('Supprimer tous vos trajets importés ? Une nouvelle synchronisation les réimportera.')) occupe(async () => { const r = await del('/api/trajets'); dire('ok', `${r.supprimes} trajet(s) supprimé(s).`); await rafraichir(); }); });

    const suivies = etat.motos.filter((m) => m.selected);
    const etape = aRelier ? 1 : (suivies.length ? 3 : 2);
    const derniere = etat.derniere_synchro
      ? `Dernière synchronisation ${fmtRelatif(etat.derniere_synchro)} (${fmtDateHeure(etat.derniere_synchro)})${etat.derniere_synchro_total === null ? '.' : ` : ${pluriel(etat.derniere_synchro_nouveaux, 'nouveau trajet', 'nouveaux trajets')} sur ${etat.derniere_synchro_total}.`}`
      : 'Jamais synchronisé.';
    const statut = h('div', { class: 'carte' },
      h('p', { class: 'statut' },
        h('span', { class: `pastille ${aRelier ? 'alerte' : 'ok'}` }, aRelier ? 'À reconnecter' : 'Connecté'), h('strong', {}, etat.email)),
      h('dl', { class: 'stats' },
        h('div', {}, h('dt', {}, 'Trajets importés'), h('dd', {}, etat.trajets_importes)),
        h('div', {}, h('dt', {}, 'Motos suivies'), h('dd', {}, suivies.length ? suivies.map((m) => m.name || m.tracker_id).join(', ') : 'Aucune'))),
      h('p', { class: 'muted' }, derniere),
      etat.derniere_synchro_erreur && !aRelier
        ? bandeau('erreur', `La dernière tentative a échoué : ${ERREURS_SYNCHRO[etat.derniere_synchro_erreur] ?? ERREURS_SYNCHRO.autre} Réessayez dans un moment.`) : null,
      etat.trajets_importes > 0 ? h('a', { href: '#/trajets' }, 'Voir mes trajets') : null);

    const danger = h('div', { class: 'actions' }, delier, reinit);
    if (aRelier) {
      return h('div', {}, etapes(etape), statut, vueLiaison({ email: etat.email, reconnexion: true }),
        suivies.length || cases.length ? h('p', { class: 'muted' }, `Motos conservées : ${cases.length ? etat.motos.map((m) => m.name || m.tracker_id).join(', ') : 'aucune'}.`) : null,
        h('hr'), danger);
    }
    return h('div', {}, etapes(etape), statut,
      h('div', { class: 'carte' },
        h('h2', {}, 'Motos suivies'),
        cases.length ? h('div', { class: 'cases' }, cases) : h('p', { class: 'muted' }, 'Aucune moto connue. Actualisez la liste.'),
        suivies.length === 0 && cases.length ? h('p', { class: 'muted petit' }, 'Cochez au moins une moto pour lancer la synchronisation.') : null,
        h('div', { class: 'actions' }, enregistrer, synchro, actualiser),
        h('hr'), danger));
  }

  await rafraichir();
  return arreterMinuteur;
}

// ---------- Ravitaillement (pit-stop) ----------
const CARBURANTS = [['sp95', 'SP95'], ['sp98', 'SP98'], ['e10', 'SP95-E10'], ['gazole', 'Gazole']];

export function vueRavitaillement(racine) {
  const msg = h('div', { 'aria-live': 'polite' });
  const lat = h('input', { type: 'number', id: 'lat', step: 'any', min: '-90', max: '90', required: true });
  const lon = h('input', { type: 'number', id: 'lon', step: 'any', min: '-180', max: '180', required: true });
  const carburant = h('select', { id: 'carb' }, CARBURANTS.map(([v, n]) => h('option', { value: v }, n)));
  const rayon = h('input', { type: 'number', id: 'rayon', value: '10', min: '1', max: '50' });
  const litres = h('input', { type: 'number', id: 'litres', value: '15', min: '1', step: 'any' });
  const conso = h('input', { type: 'number', id: 'conso', value: '5', min: '1', step: 'any' });
  const sansDetour = h('input', { type: 'checkbox', id: 'sd' });
  const position = h('button', { class: 'btn', type: 'button' }, 'Utiliser ma position');
  const chercher = h('button', { class: 'btn primaire', type: 'submit' }, 'Chercher');
  const zoneCarte = h('div', { class: 'carte-zone petite', role: 'region', 'aria-label': 'Carte des stations' });
  const resultats = h('ol', { class: 'liste stations' });

  const form = h('form', { class: 'carte formulaire grille' },
    h('div', {}, h('label', { for: 'lat' }, 'Latitude'), lat),
    h('div', {}, h('label', { for: 'lon' }, 'Longitude'), lon),
    h('div', {}, h('label', { for: 'carb' }, 'Carburant'), carburant),
    h('div', {}, h('label', { for: 'rayon' }, 'Rayon (km)'), rayon),
    h('div', {}, h('label', { for: 'litres' }, 'Plein (litres)'), litres),
    h('div', {}, h('label', { for: 'conso' }, 'Conso (L/100 km)'), conso),
    h('label', { class: 'case plein', for: 'sd' }, sansDetour, ' Ignorer le coût du détour'),
    h('div', { class: 'actions plein' }, position, chercher));

  afficher(racine, h('h1', {}, 'Pit-stop'),
    h('p', { class: 'muted' }, 'Trouvez la station la moins chère en tenant compte du détour. Prix des carburants en France.'),
    msg, form, zoneCarte, resultats);

  const carte = creerCarte(zoneCarte);
  let couche = [];
  position.addEventListener('click', () => {
    if (!navigator.geolocation) { msg.replaceChildren(bandeau('erreur', 'La géolocalisation n\'est pas disponible sur cet appareil. Saisissez les coordonnées.')); return; }
    navigator.geolocation.getCurrentPosition(
      (p) => { lat.value = p.coords.latitude.toFixed(5); lon.value = p.coords.longitude.toFixed(5); form.requestSubmit(); },
      () => msg.replaceChildren(bandeau('erreur', 'Position refusée ou indisponible. Saisissez les coordonnées à la main.')),
      { enableHighAccuracy: false, timeout: 10000 });
  });

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    msg.replaceChildren(); resultats.replaceChildren(); chercher.disabled = true;
    couche.forEach((m) => m.remove()); couche = [];
    try {
      const r = await get('/api/ravitaillement', {
        lat: lat.value, lon: lon.value, carburant: carburant.value, rayon: rayon.value,
        litres: litres.value, conso: conso.value, sans_detour: sansDetour.checked ? 'true' : '',
      });
      if (!r.stations.length) { msg.replaceChildren(bandeau('info', 'Aucune station dans ce rayon. Élargissez la recherche.')); return; }
      const points = [[Number(lat.value), Number(lon.value)]];
      r.stations.slice(0, 50).forEach((s, i) => {
        const prix = s.prix_litre === null ? 'prix indisponible' : `${s.prix_litre.toFixed(3)} €/L`;
        const nom = s.marque || s.nom || 'Station';
        if (carte) { couche.push(marqueur(carte, [s.lat, s.lon], `${nom} · ${prix}`, i < 3 && s.prix_litre !== null ? '#C6934B' : '#9BA1AB')); }
        points.push([s.lat, s.lon]);
        resultats.append(h('li', { class: i === 0 ? 'meilleure' : '' },
          h('div', {}, h('strong', {}, nom), h('div', { class: 'muted petit' }, `${s.adresse}, ${s.code_postal} ${s.ville}`)),
          h('div', { class: 'droite' }, h('strong', {}, prix),
            s.prix_litre === null ? null : h('div', { class: 'muted petit' },
              `${fmtKm(s.distance_km)} · plein ${fmtEuro(s.cout_plein_eur)} + détour ${fmtEuro(s.cout_detour_eur)} = ${fmtEuro(s.cout_total_devise, s.devise)}`),
            s.penalite_fraicheur_eur > 0 ? h('div', { class: 'muted petit' }, 'Prix ancien, pénalité de fraîcheur appliquée') : null)));
      });
      if (carte) { couche.push(marqueur(carte, points[0], 'Ma position', '#3b82f6')); carte.fitBounds(window.L.latLngBounds(points), { padding: [30, 30], maxZoom: 14 }); }
    } catch (e) {
      if (e.abonnementRequis) msg.replaceChildren(invitationAbonnement('Le ravitaillement compare les prix de carburant autour de vous et de vos trajets.'));
      else if (e.status !== 401) msg.replaceChildren(bandeau('erreur', e.message));
    } finally { chercher.disabled = false; }
  });
  return () => carte?.remove();
}
