import { get, post, put, del, ApiError } from '../api.js';
import { h, fmtKm, fmtDenivele, fmtDuree, fmtHeure, bandeau, squelette, toast } from '../dom.js';
import { icone } from '../icones.js';
import { creerCarte, marqueur, marqueurPosition, cadrer } from '../carte.js';
import { afficher, erreurVue, invitationAbonnement, ecranCarte } from './commun.js';
import { decoderPolyligne, gpx, echantillonner } from '../polyligne.js';
import { METEO_BRANCHEE } from '../config.js';

// ---------- Balade (calculateur Raido) : spec docs/itineraire.md §6 ----------
// La PWA demande un jeton court au PHP (POST /api/balade/jeton, décompte des paliers), puis appelle Raido
// (raido.runeride.fr) directement ; le PHP ne voit jamais le calcul. Le périmètre suit le moteur J3 : boucle ou
// trajet, durée ou distance, caractère (sinueux, scénique, motard, découverte), évitements. Étapes, autonomie et
// météo arrivent avec les jalons suivants.

const CLE_PREFS = 'runeride.balade';
const ETAPES_MAX = 8; // limite du moteur (raido-calcul, requete::ETAPES_MAX)
const PAR_DEFAUT = { retour: true, mode: 'duree', duree: 180, distance: 100, sinuosite: 3, scenique: 2, motard: 2, decouverte: false, intensite: 2, meteo: false, departA: '', autonomieKm: 0, reservoir: 1, evitements: ['autoroutes', 'peages'], derniere: null };
const EVITEMENTS = [['autoroutes', 'Autoroutes'], ['peages', 'Péages'], ['non_revetu', 'Non revêtu'], ['tunnels', 'Tunnels']];
const CRANS = ['aucun', 'léger', 'moyen', 'fort', 'maximal'];
const VITESSE_CIBLE_KMH = 55; // même hypothèse que le moteur pour convertir une durée en distance (spec O3)
const COULEUR_TRACE = '#C6934B';

function lirePrefs() {
  try { return { ...PAR_DEFAUT, ...JSON.parse(localStorage.getItem(CLE_PREFS) || '{}') }; } catch { return { ...PAR_DEFAUT }; }
}
function ecrirePrefs(p) { try { localStorage.setItem(CLE_PREFS, JSON.stringify(p)); } catch { /* stockage refusé */ } }
const borne = (v, min, max, repli) => (Number.isFinite(Number(v)) ? Math.min(max, Math.max(min, Number(v))) : repli);
const fmtCoord = ([lat, lon]) => `${lat.toFixed(4).replace('.', ',')} · ${lon.toFixed(4).replace('.', ',')}`;
const pct = (v) => `${Math.round((Number(v) || 0) * 100)} %`;
const coordValide = (lat, lon) => Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180;
const hex = (buf) => [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');

/** Appel direct du service Raido avec le jeton court. Le POST derrière CloudFront exige x-amz-content-sha256 (README du module Terraform). */
async function appelerRaido(base, chemin, corps, jeton) {
  const texte = JSON.stringify(corps);
  const entetes = { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${jeton}` };
  if (window.crypto?.subtle) {
    try { entetes['x-amz-content-sha256'] = hex(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(texte))); } catch { /* contexte non sécurisé : l'en-tête manquera */ }
  }
  let rep;
  try { rep = await fetch(new URL(chemin, base), { method: 'POST', headers: entetes, body: texte }); }
  catch { throw new ApiError(0, 'Impossible de joindre le calculateur. Vérifiez votre connexion.'); }
  let donnees = null;
  try { donnees = await rep.json(); } catch { /* corps non JSON */ }
  if (!rep.ok) {
    const detail = donnees?.erreur ? `${donnees.erreur[0].toUpperCase()}${donnees.erreur.slice(1)}.` : `Le calculateur a répondu ${rep.status}.`;
    throw new ApiError(rep.status, detail, donnees?.code ?? null);
  }
  return donnees ?? {};
}

function telecharger(nom, contenu, type) {
  const url = URL.createObjectURL(new Blob([contenu], { type }));
  const a = h('a', { href: url, download: nom });
  document.body.append(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function vueBalade(racine, { params, id: idBalade }) {
  const prefs = lirePrefs();
  prefs.duree = borne(prefs.duree, 60, 480, 180); prefs.distance = borne(prefs.distance, 10, 300, 100);
  for (const k of ['sinuosite', 'scenique', 'motard', 'intensite']) prefs[k] = borne(prefs[k], 0, 4, PAR_DEFAUT[k]);
  if (!['duree', 'distance'].includes(prefs.mode)) prefs.mode = 'duree';
  if (!Array.isArray(prefs.evitements)) prefs.evitements = [...PAR_DEFAUT.evitements];

  const { ecran, zoneCarte, corps, definirEtat, marge, consigne } = ecranCarte({ etat: 'moyen', titrePanneau: 'Balade', ratioMoyen: 0.6 });
  afficher(racine, ecran);
  const carte = creerCarte(zoneCarte);
  if (carte) { carte.marge = marge; setTimeout(() => carte.invalidateSize(), 0); }

  let depart = null; let arrivee = null;
  let etapes = []; let reperesEtapes = [];   // étapes obligatoires [lat, lon], dans l'ordre (8 au plus, comme le moteur)
  let repereDepart = null; let repereArrivee = null; let trace = null;
  let quota = null;                 // réponse de GET /api/balade/quota (palier, limite_km, grands_restants…)
  let connus = null;                // tronçons déjà parcourus (GET /api/balade/troncons), lus une fois par vue et seulement pour la découverte
  let reglages = null;              // préférences du Compte (GET /api/balade/preferences) : vitesses et pause, transmises à Raido à chaque calcul
  let jeton = null;                 // { valeur, type, expire } : réutilisé 15 min (autre proposition, sens inverse)
  let resultat = null;              // dernière réponse de Raido + la requête envoyée
  let enregistree = null;           // { id, titre, partagee, cle } quand le résultat vient d'être enregistré ou rechargé
  let avisAutonomie = null; let reperesStations = [];
  let compteur = 0; let pointage = null; // 'depart' | 'arrivee' | null

  // --- Points
  const etatDepart = h('p', { class: 'position-etat' }, icone('epingle'), h('span', {}, 'Choisissez un point de départ.'));
  const etatArrivee = h('p', { class: 'position-etat', hidden: true }, icone('itineraire'), h('span', {}, 'Choisissez l\'arrivée sur la carte.'));
  const boutonPosition = h('button', { class: 'puce', type: 'button' }, icone('position'), 'Ma position');
  const boutonCarte = h('button', { class: 'puce', type: 'button', 'aria-pressed': 'false' }, icone('pointer'), 'Départ sur la carte');
  const boutonArrivee = h('button', { class: 'puce', type: 'button', 'aria-pressed': 'false', hidden: true }, icone('pointer'), 'Arrivée sur la carte');
  const boutonEtape = h('button', { class: 'puce', type: 'button', 'aria-pressed': 'false' }, icone('epingle'), 'Ajouter une étape');
  const listeEtapes = h('ol', { class: 'liste etapes', hidden: true });

  // --- Essentiel
  const typeSegments = h('div', { class: 'segments deux', role: 'radiogroup', 'aria-label': 'Type de balade' },
    h('label', { class: 'segment' }, h('input', { type: 'radio', name: 'type', value: 'boucle', checked: prefs.retour }), h('span', {}, 'Boucle')),
    h('label', { class: 'segment' }, h('input', { type: 'radio', name: 'type', value: 'trajet', checked: !prefs.retour }), h('span', {}, 'Trajet A → B')));
  const modeSegments = h('div', { class: 'segments deux', role: 'radiogroup', 'aria-label': 'Durée ou distance' },
    h('label', { class: 'segment' }, h('input', { type: 'radio', name: 'mode', value: 'duree', checked: prefs.mode === 'duree' }), h('span', {}, 'Durée')),
    h('label', { class: 'segment' }, h('input', { type: 'radio', name: 'mode', value: 'distance', checked: prefs.mode === 'distance' }), h('span', {}, 'Distance')));
  const duree = h('input', { type: 'range', id: 'b-duree', min: 60, max: 480, step: 30, value: prefs.duree });
  const distance = h('input', { type: 'range', id: 'b-distance', min: 10, max: 300, step: 5, value: prefs.distance });
  const sortieDuree = h('output', { for: 'b-duree' }); const sortieDistance = h('output', { for: 'b-distance' });
  const curseur = (id, libelle, sortie, champ) => h('div', { class: 'curseur' }, h('label', { for: id }, libelle, sortie), champ);
  const blocDuree = curseur('b-duree', 'Durée souhaitée', sortieDuree, duree);
  const blocDistance = curseur('b-distance', 'Distance souhaitée', sortieDistance, distance);
  const petiteBoucle = h('button', { class: 'puce', type: 'button' }, icone('route'), 'Petite boucle 25 km');
  const calculer = h('button', { class: 'btn primaire plein', type: 'submit' }, icone('itineraire'), 'Calculer');
  const infoQuota = h('p', { class: 'muted petit', id: 'b-quota', 'aria-live': 'polite' });

  // --- Caractère
  const sinuosite = h('input', { type: 'range', id: 'b-sinu', min: 0, max: 4, step: 1, value: prefs.sinuosite });
  const scenique = h('input', { type: 'range', id: 'b-scen', min: 0, max: 4, step: 1, value: prefs.scenique });
  const motard = h('input', { type: 'range', id: 'b-mot', min: 0, max: 4, step: 1, value: prefs.motard });
  const decouverte = h('input', { type: 'checkbox', id: 'b-dec', checked: prefs.decouverte });
  const intensite = h('input', { type: 'range', id: 'b-int', min: 1, max: 4, step: 1, value: Math.max(1, prefs.intensite) });
  const sortieSinu = h('output', { for: 'b-sinu' }); const sortieScen = h('output', { for: 'b-scen' }); const sortieMot = h('output', { for: 'b-mot' }); const sortieInt = h('output', { for: 'b-int' });
  const aideDecouverte = h('span', { class: 'aide' });
  const resumeCaractere = h('span', { class: 'resume' });
  const caractere = h('details', { class: 'reglages' },
    h('summary', {}, icone('reglages', 18), 'Caractère', resumeCaractere),
    h('div', { class: 'contenu' },
      curseur('b-sinu', 'Sinueux', sortieSinu, sinuosite),
      curseur('b-scen', 'Scénique', sortieScen, scenique),
      curseur('b-mot', 'Motard', sortieMot, motard),
      h('label', { class: 'case' }, decouverte, h('span', {}, 'Découverte : éviter mes routes déjà parcourues', aideDecouverte)),
      curseur('b-int', 'Intensité de la découverte', sortieInt, intensite)));

  // --- Contraintes
  const cases = Object.fromEntries(EVITEMENTS.map(([v]) => [v, h('input', { type: 'checkbox', name: 'evitement', value: v, checked: prefs.evitements.includes(v) })]));
  const resumeContraintes = h('span', { class: 'resume' });
  // Autonomie (abonnés) : au-delà de ce que permet le réservoir, Raido insère des stations choisies parmi celles proches de la balade.
  const autonomie = h('input', { type: 'number', id: 'b-autonomie', min: 0, max: 1000, step: 10, inputmode: 'numeric', value: prefs.autonomieKm || '', placeholder: 'désactivée' });
  const niveau = h('select', { id: 'b-reservoir' }, [[1, 'Plein'], [0.75, '3/4'], [0.5, 'Moitié'], [0.25, '1/4']].map(([v, n]) => h('option', { value: String(v), selected: Number(prefs.reservoir) === v }, n)));
  const aideAutonomie = h('p', { class: 'muted petit' });
  const blocAutonomie = h('div', { class: 'groupe-autonomie' },
    h('label', { for: 'b-autonomie' }, 'Autonomie du réservoir (km)'), autonomie,
    h('label', { for: 'b-reservoir' }, 'Niveau au départ'), niveau, aideAutonomie);
  // Météo (inactive tant que METEO_BRANCHEE est faux) : éviter pluie, vent fort et froid ressenti, aux seuils du Compte, à l'heure de passage.
  const meteo = h('input', { type: 'checkbox', id: 'b-meteo', checked: prefs.meteo });
  const departA = h('input', { type: 'datetime-local', id: 'b-depart-a', value: prefs.departA });
  const blocMeteo = METEO_BRANCHEE ? h('div', { class: 'groupe-meteo' },
    h('label', { class: 'case' }, meteo, h('span', {}, 'Éviter la pluie, le vent fort et le froid', h('small', { class: 'muted petit' }, ' (seuils réglables dans le Compte)'))),
    h('label', { for: 'b-depart-a' }, 'Heure de départ prévue'), departA) : null;
  const contraintes = h('details', { class: 'reglages' },
    h('summary', {}, icone('reglages', 18), 'Éviter', resumeContraintes),
    h('div', { class: 'contenu' }, EVITEMENTS.map(([v, n]) => h('label', { class: 'case' }, cases[v], n)),
      blocMeteo, blocAutonomie,
      h('p', { class: 'muted petit' }, METEO_BRANCHEE ? 'Étapes et autonomie arrivent dans une prochaine version.' : 'Étapes, autonomie et météo arrivent dans une prochaine version.')));

  const msg = h('div', { 'aria-live': 'polite' });
  const zoneResultat = h('div', { class: 'resultat-balade' });
  const zoneBalades = h('div', { class: 'mes-balades' });
  const form = h('form', { class: 'form-balade' },
    etatDepart, etatArrivee, h('div', { class: 'puces' }, boutonPosition, boutonCarte, boutonArrivee, boutonEtape), listeEtapes,
    typeSegments, modeSegments, blocDuree, blocDistance, h('div', { class: 'puces' }, petiteBoucle),
    caractere, contraintes, calculer, infoQuota);
  corps.append(h('div', { class: 'entete-panneau titre-bureau' }, h('h1', { style: 'font-size:22px' }, 'Balade')), msg, zoneResultat, form, zoneBalades);

  // --- Réglages
  const lireRadio = (groupe) => groupe.querySelector('input:checked').value;
  const estAbonne = () => quota?.palier === 'abonne';
  const limiteKm = () => Number(quota?.limite_km) || 25;
  /** Petite boucle (gratuite, illimitée) : retour au départ et distance demandée ≤ limite ; une durée implique une distance au-delà. */
  const estPetiteBoucle = () => prefs.retour && etapes.length === 0 && (prefs.mode === 'distance' ? prefs.distance : (prefs.duree / 60) * VITESSE_CIBLE_KMH) <= limiteKm();
  function majReglages() {
    prefs.retour = lireRadio(typeSegments) === 'boucle'; prefs.mode = lireRadio(modeSegments);
    prefs.duree = Number(duree.value); prefs.distance = Number(distance.value);
    prefs.sinuosite = Number(sinuosite.value); prefs.scenique = Number(scenique.value); prefs.motard = Number(motard.value);
    prefs.decouverte = decouverte.checked && estAbonne(); prefs.intensite = Number(intensite.value);
    prefs.evitements = EVITEMENTS.map(([v]) => v).filter((v) => cases[v].checked);
    prefs.autonomieKm = Math.min(1000, Math.max(0, Math.round(Number(autonomie.value) || 0))); prefs.reservoir = Number(niveau.value) || 1;
    autonomie.disabled = niveau.disabled = !estAbonne();
    aideAutonomie.textContent = !estAbonne() ? 'Réservé aux abonnés : des stations sont insérées quand la balade dépasse votre autonomie.' : prefs.autonomieKm >= 30 ? 'Des stations proches de la balade seront insérées si elle dépasse l\'autonomie.' : prefs.autonomieKm > 0 ? 'Autonomie : 30 km au moins.' : '';
    prefs.meteo = METEO_BRANCHEE && meteo.checked; prefs.departA = METEO_BRANCHEE ? departA.value : '';
    ecrirePrefs(prefs);
    sortieDuree.textContent = fmtDuree(prefs.duree); sortieDistance.textContent = fmtKm(prefs.distance);
    // avec des étapes, c'est le parcours qui fixe la longueur : plus de curseur de durée ni de distance
    blocDuree.hidden = prefs.mode !== 'duree' || etapes.length > 0; blocDistance.hidden = prefs.mode !== 'distance' || etapes.length > 0;
    etatArrivee.hidden = boutonArrivee.hidden = prefs.retour;
    sortieSinu.textContent = CRANS[prefs.sinuosite]; sortieScen.textContent = CRANS[prefs.scenique]; sortieMot.textContent = CRANS[prefs.motard]; sortieInt.textContent = CRANS[prefs.intensite];
    decouverte.disabled = !estAbonne(); intensite.disabled = !prefs.decouverte;
    aideDecouverte.textContent = estAbonne() ? '' : 'Réservé aux abonnés.';
    resumeCaractere.textContent = `${prefs.sinuosite}·${prefs.scenique}·${prefs.motard}${prefs.decouverte ? ' · découverte' : ''}`;
    resumeContraintes.textContent = prefs.evitements.length ? EVITEMENTS.filter(([v]) => prefs.evitements.includes(v)).map(([, n]) => n.toLowerCase()).join(', ') : 'rien';
    majQuota();
  }
  function majQuota() {
    if (!quota) { infoQuota.textContent = ''; return; }
    if (estAbonne()) { infoQuota.textContent = 'Abonné : grands itinéraires illimités.'; return; }
    const petite = estPetiteBoucle();
    const restants = quota.grands_restants ?? 0;
    infoQuota.textContent = petite
      ? `Petite boucle (≤ ${limiteKm()} km) : gratuite et illimitée.`
      : `Grand itinéraire : ${restants} sur ${quota.grands_par_mois} restant${restants > 1 ? 's' : ''} ce mois-ci.`;
    calculer.disabled = !petite && restants <= 0;
  }
  petiteBoucle.addEventListener('click', () => {
    typeSegments.querySelector('input[value=boucle]').checked = true;
    modeSegments.querySelector('input[value=distance]').checked = true;
    distance.value = String(Math.min(limiteKm(), 300));
    majReglages();
  });
  for (const el of [typeSegments, modeSegments, decouverte, ...Object.values(cases)]) el.addEventListener('change', majReglages);
  for (const el of [duree, distance, sinuosite, scenique, motard, intensite, autonomie]) el.addEventListener('input', majReglages);
  niveau.addEventListener('change', majReglages);

  // --- Carte : points
  function dessinerPoint(cible, p) {
    if (!carte) return;
    if (cible === 'depart') {
      if (!repereDepart) repereDepart = marqueurPosition(carte, p, { onDeplace: (q) => definirPoint('depart', q, { libelle: 'Repère déplacé', centrer: false }) });
      else repereDepart.setLatLng(p);
    } else {
      repereArrivee?.remove();
      repereArrivee = marqueur(carte, p, 'Arrivée', '#ef4444');
    }
  }
  function definirPoint(cible, p, { libelle = null, centrer = true } = {}) {
    if (cible === 'depart') { depart = p; prefs.derniere = p; ecrirePrefs(prefs); }
    else arrivee = p;
    const etat = cible === 'depart' ? etatDepart : etatArrivee;
    etat.querySelector('span').replaceChildren(libelle ? `${libelle} · ` : '', h('span', { class: 'mono' }, fmtCoord(p)));
    dessinerPoint(cible, p);
    if (carte && centrer) carte.setView(p, Math.max(carte.getZoom(), 11));
  }
  function basculerPointage(cible) {
    pointage = pointage === cible ? null : cible;
    boutonCarte.setAttribute('aria-pressed', String(pointage === 'depart'));
    boutonArrivee.setAttribute('aria-pressed', String(pointage === 'arrivee'));
    boutonEtape.setAttribute('aria-pressed', String(pointage === 'etape'));
    consigne.hidden = !pointage;
    consigne.textContent = pointage === 'depart' ? 'Touchez la carte pour placer le départ' : pointage === 'etape' ? `Touchez la carte pour placer l\'étape ${etapes.length + 1}` : 'Touchez la carte pour placer l\'arrivée';
    zoneCarte.classList.toggle('pointage', Boolean(pointage));
    if (pointage) definirEtat('reduit');
  }
  boutonCarte.addEventListener('click', () => basculerPointage('depart'));
  boutonArrivee.addEventListener('click', () => basculerPointage('arrivee'));
  boutonEtape.addEventListener('click', () => {
    if (etapes.length >= ETAPES_MAX) { toast('info', `${ETAPES_MAX} étapes au plus.`); return; }
    basculerPointage('etape');
  });
  carte?.on('click', (e) => {
    if (!pointage) return;
    const cible = pointage; basculerPointage(cible);
    if (cible === 'etape') ajouterEtape([e.latlng.lat, e.latlng.lng]);
    else definirPoint(cible, [e.latlng.lat, e.latlng.lng], { libelle: 'Point choisi', centrer: false });
    definirEtat('moyen');
  });

  // --- Étapes : placées sur la carte, réordonnables, retirables ; le moteur calcule le parcours qui les relie (aucune distance demandée)
  function ajouterEtape(p) { etapes = [...etapes, p]; redessinerEtapes(); }
  function retirerEtape(i) { etapes = etapes.filter((_, k) => k !== i); redessinerEtapes(); }
  function deplacerEtape(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= etapes.length) return;
    const copie = [...etapes]; [copie[i], copie[j]] = [copie[j], copie[i]]; etapes = copie; redessinerEtapes();
  }
  function redessinerEtapes() {
    for (const m of reperesEtapes) m.remove();
    reperesEtapes = carte ? etapes.map((p, i) => marqueur(carte, p, `Étape ${i + 1}`, '#f59e0b')) : [];
    const rendues = resultat?.etapes?.length === etapes.length ? resultat.etapes : null;
    listeEtapes.hidden = etapes.length === 0;
    listeEtapes.replaceChildren(...etapes.map((p, i) => {
      const bouton = (texte, label, action, desactive = false) => { const b = h('button', { class: 'btn', type: 'button', 'aria-label': label, disabled: desactive }, texte); b.addEventListener('click', action); return b; };
      return h('li', { class: 'ligne-etape' },
        h('span', {}, h('strong', {}, `Étape ${i + 1}`), ' · ', h('span', { class: 'mono' }, fmtCoord(p)), rendues ? h('span', { class: 'muted' }, ` · à ${fmtKm(rendues[i].distance_m / 1000)} du départ`) : null),
        bouton('▲', `Monter l'étape ${i + 1}`, () => deplacerEtape(i, -1), i === 0),
        bouton('▼', `Descendre l'étape ${i + 1}`, () => deplacerEtape(i, 1), i === etapes.length - 1),
        bouton(icone('fermer', 16), `Retirer l'étape ${i + 1}`, () => retirerEtape(i)));
    }));
    majReglages();
  }
  function localiser() {
    if (!navigator.geolocation) { toast('erreur', 'La géolocalisation n\'est pas disponible sur cet appareil. Placez le départ sur la carte.'); return; }
    boutonPosition.disabled = true;
    navigator.geolocation.getCurrentPosition(
      (p) => { boutonPosition.disabled = false; definirPoint('depart', [p.coords.latitude, p.coords.longitude], { libelle: 'Ma position' }); },
      () => { boutonPosition.disabled = false; toast('erreur', 'Position refusée ou indisponible. Placez le départ sur la carte.'); },
      { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
  }
  boutonPosition.addEventListener('click', localiser);

  // --- Jeton court et calcul
  async function obtenirJeton(type) {
    if (jeton && jeton.type === type && Date.now() < jeton.expire - 30_000) return jeton;
    const r = await post('/api/balade/jeton', { type });
    quota = { ...quota, ...r, grands_restants: r.grands_restants, grands_utilises: r.grands_utilises };
    jeton = { valeur: r.jeton, type, expire: new Date(r.expire_a).getTime(), raidoUrl: r.raido_url };
    majQuota();
    return jeton;
  }
  /** Tronçons parcourus pour la découverte (abonnés) : lus une fois, transmis à Raido dans `troncons_connus`. */
  async function chargerConnus() {
    if (connus) return connus;
    const r = await get('/api/balade/troncons');
    connus = { graphe: r.graphe, total: r.total, complet: r.complet, liste: r.troncons };
    return connus;
  }
  function requete(variante) {
    const corps = {
      depart: { lat: depart[0], lon: depart[1] },
      retour: prefs.retour,
      preferences: { sinuosite: prefs.sinuosite, scenique: prefs.scenique, motard: prefs.motard, decouverte: prefs.decouverte ? prefs.intensite : 0 },
      evitements: prefs.evitements,
      variante,
    };
    // Réglages du Compte (spec §7.3) : Raido est sans état, il les reçoit dans chaque requête. Absents = défauts du moteur.
    if (prefs.decouverte && connus?.liste.length) corps.troncons_connus = connus.liste;
    if (reglages?.vitesses) corps.vitesses = reglages.vitesses;
    if (METEO_BRANCHEE && prefs.meteo) {
      corps.meteo = reglages?.seuils_meteo ?? {};
      if (/^\d{4}-\d\d-\d\dT\d\d:\d\d/.test(prefs.departA)) corps.depart_a = prefs.departA;
    }
    if (Number.isFinite(reglages?.pause_min_par_h) && reglages.pause_min_par_h !== 10) corps.pause_min_par_h = reglages.pause_min_par_h;
    if (!prefs.retour) corps.arrivee = { lat: arrivee[0], lon: arrivee[1] };
    else if (prefs.mode === 'duree') corps.duree_min = prefs.duree;
    if (prefs.mode === 'distance' || !prefs.retour) corps.distance_km = prefs.retour ? prefs.distance : undefined;
    if (corps.distance_km === undefined) delete corps.distance_km;
    if (etapes.length) {
      corps.etapes = etapes.map(([lat, lon]) => ({ lat, lon }));
      delete corps.duree_min; delete corps.distance_km; // le parcours fixe la longueur
    }
    return corps;
  }
  /** Stations proches du départ, de l'arrivée et des étapes (route PHP des arrêts carburant, abonnés) : 50 km chacun, 500 au plus, les moins chères d'abord. */
  async function chargerStations() {
    const centres = [depart, ...etapes, ...(prefs.retour ? [] : [arrivee])].filter(Boolean).slice(0, 4);
    const vues = new Map();
    for (const [lat, lon] of centres) {
      const r = await get('/api/ravitaillement', { lat, lon, rayon: 50, carburant: 'sp95', litres: 15, conso: 5 });
      for (const s of r.stations || []) if (!vues.has(s.id)) vues.set(s.id, s);
    }
    return [...vues.values()].sort((a, b) => (a.prix_litre ?? 99) - (b.prix_litre ?? 99)).slice(0, 500)
      .map((s) => ({ lat: s.lat, lon: s.lon, nom: [s.marque, s.nom || s.ville].filter(Boolean).join(' · ').slice(0, 80) || undefined, prix: s.prix_litre ?? undefined }));
  }
  async function lancer({ variante = 0 } = {}) {
    if (!depart) { toast('info', 'Choisissez d\'abord un point de départ.'); return; }
    if (!prefs.retour && !arrivee) { toast('info', 'Choisissez l\'arrivée sur la carte.'); return; }
    const type = estPetiteBoucle() ? 'petite_boucle' : 'grand';
    if (type === 'grand' && !estAbonne() && (quota?.grands_restants ?? 0) <= 0) {
      msg.replaceChildren(invitationAbonnement(`Vos ${quota?.grands_par_mois ?? 3} grands itinéraires du mois sont utilisés. Les petites boucles de ${limiteKm()} km restent gratuites.`));
      return;
    }
    const moi = ++compteur;
    msg.replaceChildren(); enregistree = null;
    zoneResultat.replaceChildren(bandeau('info', h('strong', {}, 'Calcul en cours… '), 'quelques secondes.'), squelette('ligne'));
    calculer.disabled = true;
    try {
      const j = await obtenirJeton(type);
      if (!j.raidoUrl) throw new ApiError(503, 'Le calculateur n\'est pas encore en service : le jeton est délivré, mais aucune adresse Raido n\'est configurée.');
      if (prefs.decouverte) {
        try { await chargerConnus(); } catch (e) { if (e.abonnementRequis) throw e; /* sans la liste : calcul sans pénalité, signalé plus bas */ }
      }
      const corps = requete(variante);
      if (estAbonne() && prefs.autonomieKm >= 30) {
        try {
          const stations = await chargerStations();
          if (stations.length) { corps.autonomie = { km: prefs.autonomieKm, reservoir: prefs.reservoir }; corps.stations = stations; }
          else avisAutonomie = 'Aucune station connue près de ce point : l\'autonomie n\'a pas été prise en compte.';
        } catch (e) { avisAutonomie = 'Les stations n\'ont pas pu être lues : l\'autonomie n\'a pas été prise en compte.'; }
      }
      const r = await appelerRaido(j.raidoUrl, prefs.retour ? '/v1/balade' : '/v1/trajet', corps, j.valeur);
      if (moi !== compteur) return;
      const avis = [];
      if (prefs.decouverte) {
        if (!connus) avis.push('Vos routes déjà parcourues n\'ont pas pu être lues : la découverte n\'a pas été appliquée.');
        else if (connus.total === 0) avis.push('Aucun de vos trajets n\'est encore rattaché au réseau : synchronisez GeoRide, puis relancez. La découverte n\'a pas pu éviter vos routes.');
        else {
          if (!connus.complet) avis.push(`Votre historique compte ${connus.total.toLocaleString('fr-FR')} tronçons : seuls les ${connus.liste.length.toLocaleString('fr-FR')} premiers sont pris en compte.`);
          if (r.graphe?.version && connus.graphe && r.graphe.version !== connus.graphe) avis.push('Vos trajets ont été rattachés à une autre version de la carte : la découverte peut être approximative jusqu\'à la prochaine synchronisation.');
        }
      }
      if (avisAutonomie) { avis.push(avisAutonomie); avisAutonomie = null; }
      resultat = { ...r, avertissements: [...(r.avertissements || []), ...avis], requete: corps, points: decoderPolyligne(r.trace), type };
      afficherResultat();
    } catch (e) {
      if (moi !== compteur) return;
      zoneResultat.replaceChildren();
      if (e.status === 403 && e.code === 'quota_epuise') { quota = { ...quota, grands_restants: 0 }; majQuota(); msg.replaceChildren(invitationAbonnement(e.message)); }
      else if (e.status === 403 && e.code === 'abonnement_requis') msg.replaceChildren(invitationAbonnement(e.message));
      else if (e.status === 403 && e.code === 'quota_requis') { jeton = null; msg.replaceChildren(bandeau('erreur', e.message)); }
      else if (e.status === 401 && e.code) { jeton = null; msg.replaceChildren(bandeau('erreur', 'Le jeton de calcul a été refusé. Réessayez.')); }
      else msg.replaceChildren(erreurVue(e) || '');
    } finally { if (moi === compteur) { calculer.disabled = false; majQuota(); } }
  }
  form.addEventListener('submit', (ev) => { ev.preventDefault(); lancer(); });

  // --- Résultat
  function dessinerTrace(points) {
    if (!carte) return;
    trace?.remove();
    trace = window.L.polyline(points, { color: COULEUR_TRACE, weight: 5, opacity: 0.9, lineJoin: 'round' }).addTo(carte);
    cadrer(carte, points, { maxZoom: 13 });
  }
  const chiffre = (valeur, libelle) => h('div', { class: 'chiffre' }, h('strong', {}, valeur), h('span', {}, libelle));
  function ecart() {
    if (!resultat.requete.retour) return null;
    if (resultat.requete.duree_min) { const d = Math.round(resultat.duree_s / 60 - resultat.requete.duree_min); return `${d >= 0 ? '+' : '−'}${fmtDuree(Math.abs(d))} par rapport à la durée demandée`; }
    if (resultat.requete.distance_km) { const d = resultat.distance_m / 1000 - resultat.requete.distance_km; return `${d >= 0 ? '+' : '−'}${fmtKm(Math.abs(d))} par rapport à la distance demandée`; }
    return null;
  }
  /** Ce qui est gardé avec la balade : sans la liste des stations (500 lignes) ni l'historique des tronçons parcourus (donnée personnelle, volumineuse). */
  function parametresEnregistres(q) { const { stations, troncons_connus: connusEnvoyes, ...reste } = q; return reste; }
  function afficherResultat() {
    if (etapes.length) redessinerEtapes(); // distances au passage de chaque étape
    const r = resultat;
    dessinerTrace(r.points);
    for (const m of reperesStations) m.remove();
    reperesStations = carte ? (r.stations || []).map((s) => marqueur(carte, [s.lat, s.lon], `Plein : ${s.nom || 'station'}`, '#16a34a')) : [];
    const pleins = (r.stations || []).length ? h('ul', { class: 'liste pleins' }, r.stations.map((s) => h('li', {}, icone('epingle', 16),
      h('span', {}, h('strong', {}, `Plein à ${fmtKm(s.distance_m / 1000)}`), s.nom ? ` · ${s.nom}` : '', Number.isFinite(s.prix) ? h('span', { class: 'mono' }, ` · ${s.prix.toFixed(3).replace('.', ',')} €/L`) : null)))) : null;
    const arriveeA = fmtHeure(new Date(Date.now() + r.duree_s * 1000).toISOString());
    const chiffres = h('div', { class: 'chiffres' },
      chiffre(fmtKm(r.distance_m / 1000), 'distance'), chiffre(fmtDuree(r.duree_s / 60), 'durée, pauses comprises'), chiffre(arriveeA, 'arrivée si départ maintenant'));
    const scores = h('div', { class: 'chiffres' },
      chiffre(pct(r.scores?.sinuosite), 'sinuosité'),
      r.denivele_m != null ? chiffre(fmtDenivele(r.denivele_m), 'dénivelé') : chiffre(pct(r.scores?.scenique), 'scénique'),
      r.requete.preferences.decouverte ? chiffre(pct(r.scores?.nouveaute), 'nouveauté') : chiffre(pct(r.scores?.motard), 'motard'));
    const autre = h('button', { class: 'btn', type: 'button', disabled: !(r.variantes > 1) }, icone('actualiser'), 'Autre proposition');
    autre.addEventListener('click', () => lancer({ variante: ((r.variante ?? 0) + 1) % Math.max(1, r.variantes || 1) }));
    const inverser = h('button', { class: 'btn', type: 'button', hidden: r.requete.retour }, icone('actualiser'), 'Inverser le sens');
    inverser.addEventListener('click', () => { [depart, arrivee] = [arrivee, depart]; etapes = [...etapes].reverse(); redessinerEtapes(); definirPoint('depart', depart, { libelle: 'Départ', centrer: false }); definirPoint('arrivee', arrivee, { libelle: 'Arrivée', centrer: false }); lancer(); });
    const gpxBtn = h('button', { class: 'btn', type: 'button' }, icone('sortie'), 'GPX');
    gpxBtn.addEventListener('click', () => telecharger(`${(enregistree?.titre || 'balade-runeride').replace(/[^\p{L}\p{N}]+/gu, '-').toLowerCase()}.gpx`, gpx(r.points, { titre: enregistree?.titre || 'Balade RuneRide', attribution: r.attribution || '' }), 'application/gpx+xml'));
    const pts = echantillonner(r.points, 10);
    const [o, ...reste] = pts; const d = reste.pop();
    const gmaps = `https://www.google.com/maps/dir/?api=1&travelmode=driving&origin=${o[0]},${o[1]}&destination=${d[0]},${d[1]}&waypoints=${reste.map((p) => `${p[0]},${p[1]}`).join('|')}`;
    const ouvrir = h('a', { class: 'btn', href: gmaps, target: '_blank', rel: 'noopener noreferrer' }, icone('itineraire'), 'Ouvrir dans Google Maps');
    const enregistrer = h('button', { class: 'btn primaire', type: 'button' }, icone('ok'), enregistree ? 'Enregistrée' : 'Enregistrer');
    enregistrer.disabled = Boolean(enregistree);
    const partager = h('button', { class: 'btn', type: 'button' }, icone('lien'), enregistree?.partagee ? 'Copier le lien' : 'Partager');
    const zonePartage = h('div', { 'aria-live': 'polite' });
    enregistrer.addEventListener('click', () => {
      // Titre suggéré : le col ou le panorama de la boucle, sinon sa distance ; utilisé tel quel si le champ reste vide.
      const suggestion = r.passe_par ? `Balade par ${r.passe_par}`.slice(0, 255) : `Balade de ${fmtKm(r.distance_m / 1000)}`;
      const titre = h('input', { type: 'text', id: 'b-titre', maxlength: 255, placeholder: suggestion, autocomplete: 'off' });
      const ok = h('button', { class: 'btn primaire', type: 'submit' }, 'Enregistrer');
      const f = h('form', { class: 'form-titre' }, h('label', { class: 'champ' }, h('span', {}, 'Titre'), titre), ok);
      f.addEventListener('submit', async (ev) => {
        ev.preventDefault(); ok.disabled = true;
        try {
          const s = await post('/api/balades', { titre: titre.value.trim() || (r.passe_par ? suggestion : ''), params: parametresEnregistres(r.requete), trace: r.trace, distance_m: r.distance_m, duree_s: r.duree_s, scores: r.scores ? { ...r.scores, ...(r.denivele_m != null ? { denivele_m: r.denivele_m } : {}) } : null, graphe_version: r.graphe?.version ?? null });
          enregistree = { id: s.id, titre: s.titre, partagee: false };
          toast('ok', `« ${s.titre} » enregistrée.`);
          afficherResultat(); chargerBalades();
        } catch (e) {
          ok.disabled = false;
          if (e.abonnementRequis) zonePartage.replaceChildren(invitationAbonnement(e.message)); else if (e.status !== 401) toast('erreur', e.message);
        }
      });
      zonePartage.replaceChildren(f); titre.focus();
    });
    partager.addEventListener('click', async () => {
      if (!enregistree) { zonePartage.replaceChildren(bandeau('info', 'Enregistrez d\'abord la balade pour obtenir un lien public.')); return; }
      partager.disabled = true;
      try {
        const p = enregistree.cle ? { cle: enregistree.cle } : await put('/api/balade/partage', { id: enregistree.id });
        enregistree = { ...enregistree, partagee: true, cle: p.cle };
        const lien = `${window.location.origin}${window.location.pathname}#/partage?cle=${p.cle}`;
        let copie = false;
        if (navigator.share) { try { await navigator.share({ title: enregistree.titre, url: lien }); copie = true; } catch { /* annulé */ } }
        if (!copie) { try { await navigator.clipboard.writeText(lien); copie = true; toast('ok', 'Lien copié.'); } catch { /* presse-papier refusé */ } }
        zonePartage.replaceChildren(bandeau('ok', h('strong', {}, 'Lien public : '), h('a', { href: lien, target: '_blank', rel: 'noopener' }, lien)));
        partager.replaceChildren(icone('lien'), 'Copier le lien');
      } catch (e) { if (e.status !== 401) toast('erreur', e.message); } finally { partager.disabled = false; }
    });
    zoneResultat.replaceChildren(
      h('div', { class: 'detail' }, h('h2', {}, enregistree?.titre || (r.requete.retour ? 'Votre boucle' : 'Votre trajet')), r.passe_par ? h('p', { class: 'passe-par' }, icone('epingle', 16), `Passe par ${r.passe_par}`) : null, ecart() ? h('p', { class: 'quand' }, ecart()) : null),
      chiffres, scores, pleins,
      ...(r.avertissements || []).map((a) => bandeau('info', a)), // déployée : replaceChildren n'aplatit pas les tableaux
      h('div', { class: 'actions-balade' }, autre, inverser, enregistrer, partager, gpxBtn, ouvrir),
      zonePartage,
      h('p', { class: 'muted petit' }, r.attribution || ''));
    definirEtat('moyen');
  }

  // --- Mes balades enregistrées
  async function chargerBalades() {
    let l;
    try { l = await get('/api/balades', { limite: 20 }); } catch (e) { if (e.status !== 401) zoneBalades.replaceChildren(erreurVue(e) || ''); return; }
    if (!l.balades.length) { zoneBalades.replaceChildren(); return; }
    const items = l.balades.map((b) => {
      const ouvrirB = h('button', { class: 'trajet', type: 'button' },
        h('span', { class: 'pastille', style: `background:${COULEUR_TRACE}` }),
        h('strong', {}, b.titre),
        h('span', { class: 'infos' }, h('span', { class: 'mono' }, fmtKm(b.distance_m / 1000)), h('span', {}, fmtDuree(b.duree_s / 60)), b.partagee ? h('span', {}, 'partagée') : null));
      ouvrirB.addEventListener('click', () => chargerBalade(b.id));
      const suppr = h('button', { class: 'btn danger', type: 'button', 'aria-label': `Supprimer ${b.titre}` }, icone('fermer'));
      suppr.addEventListener('click', async () => {
        if (!confirm(`Supprimer « ${b.titre} » ?`)) return;
        try { await del(`/api/balades?id=${b.id}`); toast('ok', 'Balade supprimée.'); if (enregistree?.id === b.id) { enregistree = null; if (resultat) afficherResultat(); } chargerBalades(); }
        catch (e) { if (e.status !== 401) toast('erreur', e.message); }
      });
      return h('li', { class: 'ligne-balade' }, ouvrirB, suppr);
    });
    zoneBalades.replaceChildren(
      h('h2', { class: 'titre-section' }, 'Mes balades', h('span', { class: 'muted' }, l.enregistrement_limite ? ` ${l.total} / ${l.enregistrement_limite}` : ` ${l.total}`)),
      h('ul', { class: 'liste' }, items));
  }
  async function chargerBalade(id) {
    const moi = ++compteur;
    zoneResultat.replaceChildren(squelette('ligne'), squelette('ligne'));
    try {
      const b = await get('/api/balade', { id });
      if (moi !== compteur) return;
      const q = b.params || {};
      if (q.depart) definirPoint('depart', [q.depart.lat, q.depart.lon], { libelle: 'Départ de la balade', centrer: false });
      if (q.arrivee) definirPoint('arrivee', [q.arrivee.lat, q.arrivee.lon], { libelle: 'Arrivée', centrer: false });
      etapes = Array.isArray(q.etapes) ? q.etapes.slice(0, ETAPES_MAX).filter((e) => Number.isFinite(e?.lat) && Number.isFinite(e?.lon)).map((e) => [e.lat, e.lon]) : [];
      resultat = { trace: b.trace, points: decoderPolyligne(b.trace), distance_m: b.distance_m, duree_s: b.duree_s, scores: b.scores, denivele_m: b.scores?.denivele_m ?? null, graphe: { version: b.graphe_version }, variantes: 1, variante: 0, avertissements: [], attribution: b.attribution, requete: { retour: !q.arrivee, preferences: q.preferences || {}, ...q } };
      enregistree = { id: b.id, titre: b.titre, partagee: b.partagee };
      afficherResultat();
    } catch (e) { if (moi !== compteur) return; zoneResultat.replaceChildren(); if (e.status !== 401) msg.replaceChildren(erreurVue(e) || ''); }
  }

  // --- Démarrage : quota, puis point de départ (adresse > balade enregistrée > position autorisée > dernière position)
  majReglages();
  (async () => {
    try { quota = await get('/api/balade/quota'); } catch (e) { if (e.status !== 401) msg.replaceChildren(erreurVue(e) || ''); }
    try { reglages = await get('/api/balade/preferences'); } catch { /* sans les réglages du Compte : défauts du moteur */ }
    majReglages();
    chargerBalades();
    const lat = Number(params.get('lat')); const lon = Number(params.get('lon'));
    if (params.get('lat') && params.get('lon') && coordValide(lat, lon)) { definirPoint('depart', [lat, lon], { libelle: 'Point choisi' }); return; }
    if (idBalade && /^\d+$/.test(idBalade)) { chargerBalade(Number(idBalade)); return; }
    try {
      const perm = await navigator.permissions?.query({ name: 'geolocation' });
      if (perm?.state === 'granted') { localiser(); return; }
    } catch { /* API absente */ }
    if (Array.isArray(prefs.derniere) && prefs.derniere.length === 2 && coordValide(prefs.derniere[0], prefs.derniere[1])) definirPoint('depart', prefs.derniere, { libelle: 'Dernier départ' });
  })();

  return () => { compteur += 1; carte?.remove(); };
}
