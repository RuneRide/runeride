// Construction DOM sûre : tout texte passe par textContent (les titres de trajets viennent de GeoRide).
export function h(balise, attrs = {}, ...enfants) {
  const el = document.createElement(balise);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === false || v === null || v === undefined) continue;
    if (k === 'class') el.className = v;
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else if (k === 'value' || k === 'checked' || k === 'disabled') el[k] = v;
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const e of enfants.flat()) {
    if (e === null || e === undefined || e === false) continue;
    el.append(e instanceof Node ? e : document.createTextNode(String(e)));
  }
  return el;
}

const dateHeure = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' });
const dateSeule = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium' });
export const fmtDateHeure = (iso) => (iso ? dateHeure.format(new Date(iso)) : '—');
export const fmtDate = (iso) => (iso ? dateSeule.format(new Date(iso)) : '—');
export const fmtDenivele = (m) => `+${Math.round(Number(m)).toLocaleString('fr-FR')} m`;
export const fmtKm = (km) => `${Number(km).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} km`;
export function fmtDuree(min) {
  const m = Math.round(min);
  return m >= 60 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}` : `${m} min`;
}
export const fmtEuro = (v, devise = 'EUR') => new Intl.NumberFormat('fr-FR', { style: 'currency', currency: devise }).format(v);

export function bandeau(type, ...contenu) {
  return h('div', { class: `bandeau ${type}`, role: type === 'erreur' ? 'alert' : 'status' }, ...contenu);
}

export const fmtVitesse = (km, min) => (min > 0 ? `${Math.round((km / min) * 60)} km/h` : '—');
export const fmtMois = (iso) => new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' }).format(new Date(iso));
export const fmtJour = (iso) => new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(iso));
export const fmtHeure = (iso) => new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
export const fmtPrixLitre = (v) => `${Number(v).toLocaleString('fr-FR', { minimumFractionDigits: 3, maximumFractionDigits: 3 })}`;

/** « il y a 3 h », « hier »… */
export function fmtRelatif(iso) {
  const s = (Date.now() - new Date(iso).getTime()) / 1000;
  const rtf = new Intl.RelativeTimeFormat('fr-FR', { numeric: 'auto' });
  if (s < 90) return 'à l\'instant';
  if (s < 3600) return rtf.format(-Math.round(s / 60), 'minute');
  if (s < 86400) return rtf.format(-Math.round(s / 3600), 'hour');
  return rtf.format(-Math.round(s / 86400), 'day');
}

/** Message bref non bloquant (succès, information, erreur d'une action). */
export function toast(type, texte, duree = 5000) {
  const zone = document.getElementById('toasts');
  if (!zone) return;
  const el = h('div', { class: `toast ${type}`, role: type === 'erreur' ? 'alert' : 'status' }, texte);
  zone.append(el);
  setTimeout(() => { el.classList.add('sortie'); setTimeout(() => el.remove(), 250); }, duree);
}

/** Bloc d'attente (squelette) : l'espace est réservé, sans saut de mise en page. */
export const squelette = (classe = '') => h('div', { class: `squelette ${classe}`, 'aria-hidden': 'true' });
