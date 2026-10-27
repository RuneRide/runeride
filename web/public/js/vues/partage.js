import { api, lireJeton } from '../api.js';
import { h, fmtKm, fmtDenivele, fmtDuree, fmtDate, bandeau, squelette } from '../dom.js';
import { icone } from '../icones.js';
import { creerCarte, marqueur, cadrer } from '../carte.js';
import { afficher, ecranCarte } from './commun.js';
import { decoderPolyligne, gpx } from '../polyligne.js';

// ---------- Balade partagée : lecture publique, sans compte (GET /api/balade/partage?cle=) ----------
export async function vuePartage(racine, { params }) {
  const { ecran, zoneCarte, corps, marge } = ecranCarte({ etat: 'moyen', titrePanneau: 'Balade partagée', ratioMoyen: 0.5 });
  corps.append(squelette('ligne'), squelette('ligne'));
  afficher(racine, ecran);
  const carte = creerCarte(zoneCarte);
  if (carte) { carte.marge = marge; setTimeout(() => carte.invalidateSize(), 0); }
  const nettoyer = () => carte?.remove();

  const cle = params.get('cle') || '';
  let b;
  try { b = await api('GET', '/api/balade/partage', { requete: { cle }, avecJeton: false }); }
  catch (e) {
    corps.replaceChildren(h('div', { class: 'vide' }, icone('itineraire', 44), h('h2', {}, 'Balade introuvable'),
      h('p', {}, e.status === 404 ? 'Ce lien n\'est plus valide : son auteur l\'a peut-être retiré.' : e.message),
      h('a', { class: 'btn primaire', href: lireJeton() ? '#/balade' : '#/connexion' }, 'Créer ma balade')));
    return nettoyer;
  }
  const points = decoderPolyligne(b.trace);
  if (carte && points.length > 1) {
    window.L.polyline(points, { color: '#C6934B', weight: 5, opacity: 0.9, lineJoin: 'round' }).addTo(carte);
    marqueur(carte, points[0], 'Départ');
    cadrer(carte, points, { maxZoom: 13 });
  }
  const chiffre = (valeur, libelle) => h('div', { class: 'chiffre' }, h('strong', {}, valeur), h('span', {}, libelle));
  const gpxBtn = h('button', { class: 'btn', type: 'button' }, icone('sortie'), 'GPX');
  gpxBtn.addEventListener('click', () => {
    const url = URL.createObjectURL(new Blob([gpx(points, { titre: b.titre, attribution: b.attribution || '' })], { type: 'application/gpx+xml' }));
    const a = h('a', { href: url, download: `${String(b.titre).replace(/[^\p{L}\p{N}]+/gu, '-').toLowerCase()}.gpx` });
    document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
  });
  corps.replaceChildren(
    h('div', { class: 'detail' }, h('h2', {}, b.titre), h('p', { class: 'quand' }, `Balade partagée, calculée le ${fmtDate(b.creee_le)}.`)),
    h('div', { class: 'chiffres' }, chiffre(fmtKm(b.distance_m / 1000), 'distance'), chiffre(fmtDuree(b.duree_s / 60), 'durée'), chiffre(`${Math.round((b.scores?.sinuosite || 0) * 100)} %`, 'sinuosité'), b.scores?.denivele_m != null ? chiffre(fmtDenivele(b.scores.denivele_m), 'dénivelé') : null),
    h('div', { class: 'actions-balade' }, gpxBtn, h('a', { class: 'btn primaire', href: lireJeton() ? '#/balade' : '#/connexion?inscription=1' }, icone('itineraire'), 'Créer ma balade')),
    bandeau('info', 'La balade est une suggestion : la signalisation et le code de la route priment.'),
    h('p', { class: 'muted petit' }, b.attribution || ''));
  return nettoyer;
}
