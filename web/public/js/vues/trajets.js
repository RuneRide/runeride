import { get } from '../api.js';
import { h, fmtKm, fmtDuree, fmtMois, fmtJour, fmtHeure, fmtDate, fmtVitesse, squelette, toast } from '../dom.js';
import { icone } from '../icones.js';
import { creerCarte, dessinerTrajets, marqueur, couleurTrajet, cadrer } from '../carte.js';
import { URL_ABONNEMENT } from '../config.js';
import { afficher, erreurVue, ecranCarte } from './commun.js';

const chiffre = (valeur, libelle, classe = '') => h('div', { class: `chiffre ${classe}` }, h('strong', {}, valeur), h('span', {}, libelle));
const pluriel = (n, mot) => `${n} ${mot}${n > 1 ? 's' : ''}`;

// ---------- Carte + liste des trajets, détail d'un trajet (même écran) ----------
export async function vueTrajets(racine, { aller, id }) {
  const { ecran, zoneCarte, corps, definirEtat, marge } = ecranCarte({ etat: 'moyen', titrePanneau: 'Mes trajets' });
  corps.append(h('div', { class: 'chiffres' }, squelette('chiffre'), squelette('chiffre'), squelette('chiffre')), squelette('ligne'), squelette('ligne'), squelette('ligne'));
  afficher(racine, ecran);

  const carte = creerCarte(zoneCarte);
  if (carte) { carte.marge = marge; setTimeout(() => carte.invalidateSize(), 0); }
  const nettoyer = () => carte?.remove();

  let carteData; let liste;
  try { [carteData, liste] = await Promise.all([get('/api/trajets/carte', { limite: 100, points: 200 }), get('/api/trajets', { limite: 100 })]); }
  catch (e) { corps.replaceChildren(erreurVue(e)); return nettoyer; }

  const trajets = liste.trajets;
  const rangCarte = new Map(carteData.trajets.map((t, i) => [t.id, i]));
  const traces = new Map(carteData.trajets.map((t) => [t.id, t.trace]));
  const dessin = carte ? dessinerTrajets(carte, carteData.trajets, { onClic: (idT) => choisir(idT) }) : null;
  const boutons = new Map();
  let selection = null;
  let reperes = [];

  const totalKm = trajets.reduce((s, t) => s + Number(t.distance_km || 0), 0);
  const totalMin = trajets.reduce((s, t) => s + Number(t.duree_min || 0), 0);

  function rendreListe() {
    boutons.clear();
    const groupes = new Map();
    for (const t of trajets) {
      const d = new Date(t.debut);
      const cle = `${d.getFullYear()}-${d.getMonth()}`;
      if (!groupes.has(cle)) groupes.set(cle, { titre: fmtMois(t.debut), items: [] });
      groupes.get(cle).items.push(t);
    }
    const sections = [...groupes.values()].map((g) => {
      const km = g.items.reduce((s, t) => s + Number(t.distance_km || 0), 0);
      const ul = h('ul', { class: 'liste' }, g.items.map((t) => {
        const b = h('button', { class: 'trajet', type: 'button', 'aria-current': 'false', onclick: () => choisir(t.id) },
          h('span', { class: 'pastille', style: `background:${couleurTrajet(rangCarte.get(t.id) ?? 0)}` }),
          h('strong', {}, t.titre || 'Trajet'),
          h('span', { class: 'infos' }, h('span', {}, `${fmtJour(t.debut)} · ${fmtHeure(t.debut)}`), h('span', { class: 'mono' }, fmtKm(t.distance_km)), h('span', { class: 'mono' }, fmtDuree(t.duree_min))));
        boutons.set(t.id, b);
        return h('li', {}, b);
      }));
      return h('section', { class: 'mois' }, h('h2', {}, g.titre, h('span', {}, `${pluriel(g.items.length, 'trajet')} · ${fmtKm(km)}`)), ul);
    });
    corps.replaceChildren(...[
      h('div', { class: 'chiffres' }, chiffre(String(liste.total), liste.total > 1 ? 'trajets' : 'trajet'), chiffre(fmtKm(totalKm).replace(' km', ''), 'km', 'accent'), chiffre(fmtDuree(totalMin), 'au total')),
      liste.historique_limite ? h('div', { class: 'bandeau info' }, `Historique gratuit limité : trajets depuis le ${fmtDate(liste.depuis)}. `, h('a', { href: URL_ABONNEMENT }, 'Les abonnés gardent tout l\'historique.')) : null,
      ...sections].filter(Boolean));
    corps.scrollTop = 0;
  }

  function rendreVide() {
    corps.replaceChildren(h('div', { class: 'vide' },
      icone('route', 44),
      h('h2', {}, 'Aucun trajet pour le moment'),
      h('p', {}, 'Liez votre compte GeoRide : vos sorties apparaîtront ici, tracées sur la carte.'),
      h('a', { class: 'btn primaire', href: '#/compte' }, icone('lien'), 'Lier GeoRide')));
  }

  function rendreDetail(t) {
    const trace = traces.get(t.id) || [];
    const fin = trace[trace.length - 1];
    const i = trajets.findIndex((x) => x.id === t.id);
    const suivant = trajets[i - 1]; const precedent = trajets[i + 1]; // liste du plus récent au plus ancien
    corps.replaceChildren(h('div', { class: 'detail' },
      h('button', { class: 'retour', type: 'button', onclick: () => choisir(null) }, icone('gauche'), 'Tous les trajets'),
      h('h2', {}, t.titre || 'Trajet'),
      h('p', { class: 'quand' }, `${fmtJour(t.debut)} · ${fmtHeure(t.debut)} → ${fmtHeure(t.fin)}`),
      h('div', { class: 'grille-chiffres' },
        chiffre(fmtKm(t.distance_km), 'Distance', 'accent'), chiffre(fmtDuree(t.duree_min), 'Durée'),
        chiffre(fmtVitesse(t.distance_km, t.duree_min), 'Vitesse moyenne'), chiffre(fmtHeure(t.fin), 'Arrivée')),
      fin ? h('a', { class: 'btn plein', href: `#/ravitaillement?lat=${fin[0]}&lon=${fin[1]}` }, icone('carburant'), 'Pit-stop près de l\'arrivée') : null,
      fin ? h('a', { class: 'btn plein', href: `#/balade?lat=${fin[0]}&lon=${fin[1]}` }, icone('itineraire'), 'Balade depuis l\'arrivée') : null,
      h('div', { class: 'nav-trajets' },
        h('button', { class: 'btn', type: 'button', disabled: !precedent, onclick: () => choisir(precedent.id) }, icone('gauche'), 'Plus ancien'),
        h('button', { class: 'btn', type: 'button', disabled: !suivant, onclick: () => choisir(suivant.id) }, 'Plus récent', icone('droite')))));
    corps.scrollTop = 0;
  }

  function choisir(idT) {
    reperes.forEach((m) => m.remove()); reperes = [];
    const t = idT == null ? null : trajets.find((x) => x.id === idT);
    selection = t ? t.id : null;
    try { history.replaceState(null, '', selection ? `#/trajet/${selection}` : '#/trajets'); } catch { /* sans historique */ }
    boutons.forEach((b, k) => b.setAttribute('aria-current', String(k === selection)));
    if (!t) { rendreListe(); dessin?.activer(null); dessin?.ajuster(); definirEtat('moyen'); boutons.forEach((b, k) => b.setAttribute('aria-current', 'false')); return; }
    rendreDetail(t);
    definirEtat('moyen');
    if (carte) {
      const trace = traces.get(t.id) || [];
      dessin.activer(t.id);
      if (trace.length) {
        reperes.push(marqueur(carte, trace[0], 'Départ', '#10b981'), marqueur(carte, trace[trace.length - 1], 'Arrivée', '#ef4444'));
        cadrer(carte, trace);
      }
    }
  }

  if (!trajets.length) rendreVide();
  else {
    rendreListe();
    if (id) {
      if (trajets.some((t) => String(t.id) === String(id))) choisir(trajets.find((t) => String(t.id) === String(id)).id);
      else toast('info', 'Ce trajet n\'est pas disponible. Voici vos trajets.');
    }
  }
  return nettoyer;
}
