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
const relatif = new Intl.RelativeTimeFormat('fr-FR', { numeric: 'auto' });
/** « il y a 2 heures », « hier »… ; sous la minute : « à l'instant ». */
export function fmtRelatif(iso, maintenant = Date.now()) {
  if (!iso) return '—';
  const s = Math.round((new Date(iso).getTime() - maintenant) / 1000);
  const a = Math.abs(s);
  if (a < 60) return 'à l\'instant';
  if (a < 3600) return relatif.format(Math.round(s / 60), 'minute');
  if (a < 86400) return relatif.format(Math.round(s / 3600), 'hour');
  return relatif.format(Math.round(s / 86400), 'day');
}
export const fmtKm = (km) => `${Number(km).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} km`;
export function fmtDuree(min) {
  const m = Math.round(min);
  return m >= 60 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}` : `${m} min`;
}
export const fmtEuro = (v, devise = 'EUR') => new Intl.NumberFormat('fr-FR', { style: 'currency', currency: devise }).format(v);

export function bandeau(type, ...contenu) {
  return h('div', { class: `bandeau ${type}`, role: type === 'erreur' ? 'alert' : 'status' }, ...contenu);
}
