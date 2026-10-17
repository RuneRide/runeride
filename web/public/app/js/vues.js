import { get, post, put, del, ecrireJeton, ApiError } from './api.js';
import { h, bandeau, fmtDateHeure, fmtDate, fmtKm, fmtDuree, fmtEuro } from './dom.js';
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
  afficher(racine, h('div', { class: 'etroit' }, titre, form));
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
export async function vueGeoride(racine) {
  const msg = h('div', { 'aria-live': 'polite' });
  const corps = h('div');
  afficher(racine, h('h1', {}, 'GeoRide'), msg, corps);
  const dire = (type, texte) => msg.replaceChildren(bandeau(type, texte));

  async function rafraichir() {
    corps.replaceChildren(chargement());
    let etat;
    try { etat = await get('/api/georide'); } catch (e) { afficher(corps, erreurVue(e)); return; }
    corps.replaceChildren(etat.lie ? vueLie(etat) : vueLiaison());
  }

  function vueLiaison() {
    const email = h('input', { type: 'email', id: 'g-email', required: true, autocomplete: 'username' });
    const mdp = h('input', { type: 'password', id: 'g-mdp', required: true, autocomplete: 'current-password' });
    const depuis = h('input', { type: 'date', id: 'g-depuis' });
    const bouton = h('button', { class: 'btn primaire', type: 'submit' }, 'Lier mon compte');
    const form = h('form', { class: 'carte formulaire' },
      h('p', {}, 'Saisissez vos identifiants GeoRide. Ils servent uniquement à récupérer vos trajets ; le mot de passe est conservé chiffré.'),
      h('label', { for: 'g-email' }, 'E-mail GeoRide'), email,
      h('label', { for: 'g-mdp' }, 'Mot de passe GeoRide'), mdp,
      h('label', { for: 'g-depuis' }, 'Importer à partir du (facultatif)'), depuis,
      bouton);
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      bouton.disabled = true; msg.replaceChildren();
      try {
        const corpsReq = { email: email.value.trim(), mot_de_passe: mdp.value };
        if (depuis.value) corpsReq.suivi_depuis = depuis.value;
        await put('/api/georide', corpsReq);
        await get('/api/georide/motos'); // récupère la liste des motos juste après la liaison
        dire('ok', 'Compte GeoRide lié. Choisissez maintenant les motos à suivre.');
        await rafraichir();
      } catch (e) { if (e.status !== 401) dire('erreur', e.message); bouton.disabled = false; }
    });
    return form;
  }

  function vueLie(etat) {
    const cases = etat.motos.map((m) => {
      const c = h('input', { type: 'checkbox', value: m.tracker_id, checked: m.selected, id: `m-${m.tracker_id}` });
      return h('label', { class: 'case', for: c.id }, c, ' ', m.name || `Moto ${m.tracker_id}`);
    });
    const enregistrer = h('button', { class: 'btn', type: 'button' }, 'Enregistrer la sélection');
    const synchro = h('button', { class: 'btn primaire', type: 'button' }, 'Synchroniser maintenant');
    const actualiser = h('button', { class: 'btn', type: 'button' }, 'Actualiser la liste des motos');
    const delier = h('button', { class: 'btn danger', type: 'button' }, 'Délier GeoRide');
    const reinit = h('button', { class: 'btn danger', type: 'button' }, 'Supprimer mes trajets importés');

    const occupe = async (btn, fn) => {
      const boutons = [enregistrer, synchro, actualiser, delier, reinit];
      boutons.forEach((b) => { b.disabled = true; });
      msg.replaceChildren();
      try { await fn(); } catch (e) { if (e.status !== 401) dire('erreur', e.message); }
      boutons.forEach((b) => { b.disabled = false; });
    };
    const choisies = () => cases.map((l) => l.querySelector('input')).filter((i) => i.checked).map((i) => i.value);

    enregistrer.addEventListener('click', () => occupe(enregistrer, async () => { await put('/api/georide/motos', { motos: choisies() }); dire('ok', 'Sélection enregistrée.'); }));
    actualiser.addEventListener('click', () => occupe(actualiser, async () => { await get('/api/georide/motos'); await rafraichir(); }));
    synchro.addEventListener('click', () => occupe(synchro, async () => {
      await put('/api/georide/motos', { motos: choisies() });
      const r = await post('/api/georide/synchronisation');
      dire('ok', `Synchronisation terminée : ${r.nouveaux} nouveau${r.nouveaux > 1 ? 'x' : ''} trajet${r.nouveaux > 1 ? 's' : ''}, ${r.total} au total.`);
      await rafraichir();
    }));
    delier.addEventListener('click', () => { if (confirm('Délier votre compte GeoRide ? Vos trajets déjà importés sont conservés.')) occupe(delier, async () => { await del('/api/georide'); await rafraichir(); }); });
    reinit.addEventListener('click', () => { if (confirm('Supprimer tous vos trajets importés ? Une nouvelle synchronisation les réimportera.')) occupe(reinit, async () => { const r = await del('/api/trajets'); dire('ok', `${r.supprimes} trajet(s) supprimé(s).`); }); });

    return h('div', { class: 'carte' },
      h('p', {}, 'Compte lié : ', h('strong', {}, etat.email)),
      h('p', { class: 'muted' }, etat.derniere_synchro ? `Dernière synchronisation : ${fmtDateHeure(etat.derniere_synchro)}` : 'Jamais synchronisé.'),
      h('h2', {}, 'Motos suivies'),
      cases.length ? h('div', { class: 'cases' }, cases) : h('p', { class: 'muted' }, 'Aucune moto connue. Actualisez la liste.'),
      h('div', { class: 'actions' }, enregistrer, synchro, actualiser),
      h('hr'),
      h('div', { class: 'actions' }, delier, reinit));
  }

  await rafraichir();
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
