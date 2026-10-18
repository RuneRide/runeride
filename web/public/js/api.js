import { CLE_JETON } from './config.js';

// localStorage peut être absent ou refusé (navigation privée, stockage bloqué) : repli en mémoire.
let enMemoire = null;
export function lireJeton() {
  try { return localStorage.getItem(CLE_JETON) || enMemoire; } catch { return enMemoire; }
}
export function ecrireJeton(jeton) {
  enMemoire = jeton || null;
  try { jeton ? localStorage.setItem(CLE_JETON, jeton) : localStorage.removeItem(CLE_JETON); } catch { /* repli mémoire */ }
}

export class ApiError extends Error {
  constructor(status, message, code = null) {
    super(message);
    this.status = status;
    this.code = code;
  }
  get abonnementRequis() { return this.status === 403 && this.code === 'abonnement_requis'; }
}

/** Appel JSON. 401 : jeton effacé et événement « session-expiree » (l'interface revient à la connexion). */
export async function api(methode, chemin, { corps, requete, avecJeton = true } = {}) {
  const url = new URL(chemin, window.location.origin);
  for (const [k, v] of Object.entries(requete || {})) {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
  }
  const entetes = { Accept: 'application/json' };
  if (corps !== undefined) entetes['Content-Type'] = 'application/json';
  const jeton = lireJeton();
  if (avecJeton && jeton) entetes.Authorization = `Bearer ${jeton}`;

  let rep;
  try {
    rep = await fetch(url, { method: methode, headers: entetes, body: corps === undefined ? undefined : JSON.stringify(corps) });
  } catch {
    throw new ApiError(0, 'Impossible de joindre le serveur. Vérifiez votre connexion.');
  }
  let donnees = null;
  try { donnees = await rep.json(); } catch { /* corps vide ou non JSON */ }
  if (rep.ok) return donnees ?? {};

  if (rep.status === 401 && avecJeton) {
    ecrireJeton(null);
    window.dispatchEvent(new Event('session-expiree'));
    throw new ApiError(401, 'Votre session a expiré. Reconnectez-vous.');
  }
  throw new ApiError(rep.status, messageErreur(rep.status, donnees), donnees?.code ?? null);
}

function messageErreur(status, donnees) {
  const detail = donnees?.erreur;
  if (status === 429) return detail ? `Trop de requêtes : ${detail}.` : 'Trop de requêtes. Patientez un moment avant de réessayer.';
  if (status === 403 && donnees?.code === 'abonnement_requis') return detail || 'Cette fonction est réservée aux abonnés.';
  if (status >= 500) return status === 502 && detail ? `${detail[0].toUpperCase()}${detail.slice(1)}.` : 'Le serveur rencontre un problème. Réessayez dans un instant.';
  return detail ? `${detail[0].toUpperCase()}${detail.slice(1)}.` : `Erreur ${status}.`;
}

export const get = (chemin, requete) => api('GET', chemin, { requete });
export const post = (chemin, corps, opts = {}) => api('POST', chemin, { corps: corps ?? {}, ...opts });
export const put = (chemin, corps) => api('PUT', chemin, { corps });
export const del = (chemin) => api('DELETE', chemin);
