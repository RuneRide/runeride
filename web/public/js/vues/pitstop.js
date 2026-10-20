import { get } from '../api.js';
import { h, fmtKm, fmtEuro, fmtPrixLitre, squelette, toast } from '../dom.js';
import { icone } from '../icones.js';
import { creerCarte, marqueurPrix, marqueurPosition, cadrer } from '../carte.js';
import { afficher, erreurVue, invitationAbonnement, ecranCarte } from './commun.js';

const CARBURANTS = [['e10', 'E10'], ['sp98', 'SP98'], ['sp95', 'SP95'], ['gazole', 'Gazole']];
const CLE_PREFS = 'runeride.pitstop';
const PAR_DEFAUT = { carburant: 'e10', rayon: 10, litres: 15, conso: 5, sansDetour: false, derniere: null };

function lirePrefs() {
  try { return { ...PAR_DEFAUT, ...JSON.parse(localStorage.getItem(CLE_PREFS) || '{}') }; } catch { return { ...PAR_DEFAUT }; }
}
function ecrirePrefs(p) { try { localStorage.setItem(CLE_PREFS, JSON.stringify(p)); } catch { /* stockage refusé */ } }
const nombre = (v, repli) => (Number.isFinite(Number(v)) && Number(v) > 0 ? Number(v) : repli);
const fmtCoord = ([lat, lon]) => `${lat.toFixed(4).replace('.', ',')} · ${lon.toFixed(4).replace('.', ',')}`;

// ---------- Ravitaillement (pit-stop) ----------
export function vueRavitaillement(racine, { params }) {
  const prefs = lirePrefs();
  prefs.rayon = nombre(prefs.rayon, 10); prefs.litres = nombre(prefs.litres, 15); prefs.conso = nombre(prefs.conso, 5);
  if (!CARBURANTS.some(([v]) => v === prefs.carburant)) prefs.carburant = 'e10';

  const { ecran, zoneCarte, corps, definirEtat, marge, consigne } = ecranCarte({ etat: 'moyen', titrePanneau: 'Pit-stop', ratioMoyen: 0.6 });
  afficher(racine, ecran); // la carte est créée une fois l'écran dans la page (sinon Leaflet fige un mauvais positionnement)
  const carte = creerCarte(zoneCarte);
  if (carte) { carte.marge = marge; setTimeout(() => carte.invalidateSize(), 0); }

  let position = null; let reperePosition = null;
  let marqueurs = []; let cartes = []; let actif = -1;
  let compteur = 0; let minuterie = null; let pointage = false;

  // --- Position
  const etatPosition = h('p', { class: 'position-etat' }, icone('epingle'), h('span', {}, 'Choisissez un point de départ.'));
  const boutonPosition = h('button', { class: 'puce', type: 'button' }, icone('position'), 'Ma position');
  const boutonTrajet = h('button', { class: 'puce', type: 'button' }, icone('route'), 'Dernier trajet');
  const boutonCarte = h('button', { class: 'puce', type: 'button', 'aria-pressed': 'false' }, icone('pointer'), 'Sur la carte');
  const lienBalade = h('a', { class: 'puce', href: '#/balade', hidden: true }, icone('itineraire'), 'Balade depuis ici');

  // --- Carburant et réglages
  const segments = h('div', { class: 'segments', role: 'radiogroup', 'aria-label': 'Carburant' }, CARBURANTS.map(([v, n]) =>
    h('label', { class: 'segment' }, h('input', { type: 'radio', name: 'carburant', value: v, checked: v === prefs.carburant }), h('span', {}, n))));
  const rayon = h('input', { type: 'range', id: 'rayon', min: 1, max: 50, step: 1, value: prefs.rayon });
  const litres = h('input', { type: 'range', id: 'litres', min: 5, max: 40, step: 1, value: prefs.litres });
  const conso = h('input', { type: 'range', id: 'conso', min: 2, max: 12, step: 0.5, value: prefs.conso });
  const sansDetour = h('input', { type: 'checkbox', id: 'sd', checked: prefs.sansDetour });
  const sortieRayon = h('output', { for: 'rayon' }); const sortieLitres = h('output', { for: 'litres' }); const sortieConso = h('output', { for: 'conso' });
  const resume = h('span', { class: 'resume' });
  const curseur = (id, libelle, sortie, champ) => h('div', { class: 'curseur' }, h('label', { for: id }, libelle, sortie), champ);
  const reglages = h('details', { class: 'reglages' },
    h('summary', {}, icone('reglages', 18), 'Réglages', resume),
    h('div', { class: 'contenu' },
      curseur('rayon', 'Rayon de recherche', sortieRayon, rayon),
      curseur('litres', 'Taille du plein', sortieLitres, litres),
      curseur('conso', 'Consommation', sortieConso, conso),
      h('label', { class: 'case' }, sansDetour, 'Ignorer le coût du détour')));

  const msg = h('div', { 'aria-live': 'polite' });
  const resultats = h('div', { class: 'resultats' });

  corps.append(
    h('div', { class: 'entete-panneau titre-bureau' }, h('h1', { style: 'font-size:22px' }, 'Pit-stop')),
    etatPosition, h('div', { class: 'puces' }, boutonPosition, boutonTrajet, boutonCarte, lienBalade),
    segments, reglages, msg, resultats);

  const lireCarburant = () => segments.querySelector('input:checked').value;
  function majReglages() {
    prefs.carburant = lireCarburant(); prefs.rayon = Number(rayon.value); prefs.litres = Number(litres.value); prefs.conso = Number(conso.value); prefs.sansDetour = sansDetour.checked;
    sortieRayon.textContent = `${prefs.rayon} km`; sortieLitres.textContent = `${prefs.litres} L`; sortieConso.textContent = `${prefs.conso.toLocaleString('fr-FR', { minimumFractionDigits: 1 })} L/100`;
    resume.textContent = `${prefs.rayon} km · ${prefs.litres} L`;
    ecrirePrefs(prefs);
  }
  majReglages();

  // --- Résultats
  function viderResultats() {
    marqueurs.forEach((m) => m.remove()); marqueurs = []; cartes = []; actif = -1;
    resultats.replaceChildren();
  }
  function activer(i, { depuisCarte = false } = {}) {
    if (i === actif) return;
    actif = i;
    cartes.forEach((c, k) => c.setAttribute('aria-current', String(k === i)));
    marqueurs.forEach((m, k) => m.activer(k === i));
    if (i >= 0 && depuisCarte) { definirEtat('moyen'); cartes[i].scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    if (i >= 0 && !depuisCarte && carte) { const ll = marqueurs[i].getLatLng(); carte.panTo(ll); }
  }

  function afficherStations(stations) {
    viderResultats();
    if (!stations.length) {
      resultats.replaceChildren(h('div', { class: 'vide' }, icone('carburant', 44), h('h2', {}, 'Aucune station ici'), h('p', {}, 'Aucune station dans ce rayon. Élargissez la recherche ou déplacez le repère.')));
      return;
    }
    const points = [position];
    const liste = h('ul', { class: 'stations' });
    let meilleureIdx = stations.findIndex((s) => s.prix_litre !== null);
    stations.slice(0, 50).forEach((s, i) => {
      const nom = s.marque || s.nom || 'Station';
      const aPrix = s.prix_litre !== null;
      const meilleur = i === meilleureIdx;
      const article = h('article', { class: `station${meilleur ? ' meilleure' : ''}`, tabindex: 0, 'aria-current': 'false' },
        h('div', { class: 'rang' }, String(i + 1)),
        h('div', {}, h('h3', {}, nom), h('div', { class: 'adresse' }, `${s.adresse}, ${s.code_postal} ${s.ville}`)),
        h('div', { class: 'prix' }, aPrix ? [h('strong', {}, fmtPrixLitre(s.prix_litre)), h('span', {}, '€/L')] : h('span', {}, 'prix indisponible')),
        aPrix ? h('div', { class: 'cout' }, `À ${fmtKm(s.distance_km)} · plein ${fmtEuro(s.cout_plein_eur)} + détour ${fmtEuro(s.cout_detour_eur)} = `, h('b', {}, fmtEuro(s.cout_total_devise, s.devise))) : null,
        h('div', { class: 'pied' },
          meilleur ? h('span', { class: 'badge accent' }, 'Meilleur choix') : null,
          s.penalite_fraicheur_eur > 0 ? h('span', { class: 'badge warn', title: 'Pénalité de fraîcheur appliquée au coût' }, 'Prix ancien') : null,
          h('a', { class: 'btn', href: `https://www.google.com/maps/dir/?api=1&destination=${s.lat},${s.lon}`, target: '_blank', rel: 'noopener noreferrer', 'aria-label': `Itinéraire vers ${nom}`, onclick: (e) => e.stopPropagation() }, icone('itineraire'), 'Itinéraire')));
      article.addEventListener('click', () => activer(i));
      article.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target === article) activer(i); });
      cartes.push(article);
      liste.append(h('li', {}, article));
      if (carte) marqueurs.push(marqueurPrix(carte, [s.lat, s.lon], aPrix ? fmtPrixLitre(s.prix_litre) : '—', { meilleur, onClic: () => activer(i, { depuisCarte: true }) }));
      points.push([s.lat, s.lon]);
    });
    resultats.replaceChildren(
      h('div', { class: 'tri' }, h('span', {}, `${stations.length} station${stations.length > 1 ? 's' : ''}`), h('span', {}, 'classées par coût total')),
      liste);
    if (carte) cadrer(carte, points, { maxZoom: 14 });
  }

  async function chercher() {
    if (!position) return;
    const moi = ++compteur;
    msg.replaceChildren();
    viderResultats();
    resultats.replaceChildren(squelette('ligne'), squelette('ligne'), squelette('ligne'));
    try {
      const r = await get('/api/ravitaillement', {
        lat: position[0], lon: position[1], carburant: prefs.carburant, rayon: prefs.rayon,
        litres: prefs.litres, conso: prefs.conso, sans_detour: prefs.sansDetour ? 'true' : '',
      });
      if (moi !== compteur) return;
      afficherStations(r.stations);
    } catch (e) {
      if (moi !== compteur) return;
      resultats.replaceChildren();
      if (e.abonnementRequis) msg.replaceChildren(invitationAbonnement('Le ravitaillement compare les prix de carburant autour de vous et de vos trajets.'));
      else msg.replaceChildren(erreurVue(e) || '');
    }
  }
  const chercherPlusTard = () => { clearTimeout(minuterie); minuterie = setTimeout(chercher, 350); };

  function definirPosition(p, { libelle = null, centrer = true } = {}) {
    position = p;
    prefs.derniere = p; ecrirePrefs(prefs);
    lienBalade.hidden = false; lienBalade.href = `#/balade?lat=${p[0]}&lon=${p[1]}`;
    etatPosition.querySelector('span').replaceChildren(libelle ? `${libelle} · ` : '', h('span', { class: 'mono' }, fmtCoord(p)));
    if (carte) {
      if (!reperePosition) reperePosition = marqueurPosition(carte, p, { onDeplace: (q) => definirPosition(q, { libelle: 'Repère déplacé', centrer: false }) });
      else reperePosition.setLatLng(p);
      if (centrer) carte.setView(p, Math.max(carte.getZoom(), 12));
    }
    chercher();
  }

  // --- Actions
  function localiser() {
    if (!navigator.geolocation) { toast('erreur', 'La géolocalisation n\'est pas disponible sur cet appareil. Placez le repère sur la carte.'); return; }
    boutonPosition.disabled = true;
    navigator.geolocation.getCurrentPosition(
      (p) => { boutonPosition.disabled = false; definirPosition([p.coords.latitude, p.coords.longitude], { libelle: 'Ma position' }); },
      () => { boutonPosition.disabled = false; toast('erreur', 'Position refusée ou indisponible. Placez le repère sur la carte.'); },
      { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
  }
  boutonPosition.addEventListener('click', localiser);

  boutonTrajet.addEventListener('click', async () => {
    boutonTrajet.disabled = true;
    try {
      const l = await get('/api/trajets', { limite: 1 });
      const t = l.trajets[0];
      if (!t) { toast('info', 'Aucun trajet pour le moment.'); return; }
      const d = await get('/api/trajet', { id: t.id });
      const fin = d.trace[d.trace.length - 1];
      if (!fin) { toast('info', 'Ce trajet n\'a pas de tracé.'); return; }
      definirPosition(fin, { libelle: 'Fin du dernier trajet' });
    } catch (e) { if (e.status !== 401) toast('erreur', e.message); } finally { boutonTrajet.disabled = false; }
  });

  function basculerPointage(actifNouveau) {
    pointage = actifNouveau;
    boutonCarte.setAttribute('aria-pressed', String(pointage));
    consigne.hidden = !pointage;
    consigne.textContent = 'Touchez la carte pour placer le repère';
    zoneCarte.classList.toggle('pointage', pointage);
    if (pointage) definirEtat('reduit');
  }
  boutonCarte.addEventListener('click', () => basculerPointage(!pointage));
  carte?.on('click', (e) => { if (pointage) { basculerPointage(false); definirPosition([e.latlng.lat, e.latlng.lng], { libelle: 'Point choisi', centrer: false }); definirEtat('moyen'); } });

  segments.addEventListener('change', () => { majReglages(); chercher(); });
  for (const c of [rayon, litres, conso]) c.addEventListener('input', () => { majReglages(); chercherPlusTard(); });
  sansDetour.addEventListener('change', () => { majReglages(); chercher(); });

  // --- Démarrage : adresse (depuis un trajet) > position déjà autorisée > dernière position > invitation
  function inviter() {
    resultats.replaceChildren(h('div', { class: 'vide' }, icone('position', 44), h('h2', {}, 'Où faites-vous le plein ?'),
      h('p', {}, 'Utilisez votre position, la fin de votre dernier trajet, ou placez le repère sur la carte.'),
      h('button', { class: 'btn primaire', type: 'button', onclick: localiser }, icone('position'), 'Utiliser ma position')));
  }
  const lat = Number(params.get('lat')); const lon = Number(params.get('lon'));
  (async () => {
    if (params.get('lat') && params.get('lon') && Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180) { definirPosition([lat, lon], { libelle: 'Arrivée du trajet' }); return; }
    try {
      const perm = await navigator.permissions?.query({ name: 'geolocation' });
      if (perm?.state === 'granted') { localiser(); return; }
    } catch { /* API absente */ }
    if (Array.isArray(prefs.derniere) && prefs.derniere.length === 2) { definirPosition(prefs.derniere, { libelle: 'Dernière position' }); return; }
    inviter();
  })();

  return () => { clearTimeout(minuterie); compteur += 1; carte?.remove(); };
}
