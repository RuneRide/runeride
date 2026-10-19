// Polyligne encodée (format Google, précision 1e-5) telle que la rend Raido, et export GPX 1.1. Sans dépendance.

/** Décode une polyligne encodée en liste de [lat, lon]. Chaîne vide ou invalide : liste vide. */
export function decoderPolyligne(texte) {
  const points = [];
  if (typeof texte !== 'string') return points;
  let i = 0; let lat = 0; let lon = 0;
  const lire = () => {
    let resultat = 0; let decalage = 0; let octet;
    do {
      if (i >= texte.length) return null;
      octet = texte.charCodeAt(i++) - 63;
      resultat |= (octet & 0x1f) << decalage;
      decalage += 5;
    } while (octet >= 0x20);
    return (resultat & 1) ? ~(resultat >> 1) : (resultat >> 1);
  };
  while (i < texte.length) {
    const dLat = lire(); if (dLat === null) break;
    const dLon = lire(); if (dLon === null) break;
    lat += dLat; lon += dLon;
    points.push([lat / 1e5, lon / 1e5]);
  }
  return points;
}

/** Encode une liste de [lat, lon] (pour les tests et le rejeu d'une balade enregistrée). */
export function encoderPolyligne(points) {
  let sortie = ''; let lat0 = 0; let lon0 = 0;
  const ecrire = (v) => {
    let n = v < 0 ? ~(v << 1) : (v << 1);
    while (n >= 0x20) { sortie += String.fromCharCode((0x20 | (n & 0x1f)) + 63); n >>= 5; }
    sortie += String.fromCharCode(n + 63);
  };
  for (const [lat, lon] of points) {
    const la = Math.round(lat * 1e5); const lo = Math.round(lon * 1e5);
    ecrire(la - lat0); ecrire(lo - lon0);
    lat0 = la; lon0 = lo;
  }
  return sortie;
}

const xml = (s) => String(s).replace(/[<>&'"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', "'": '&apos;', '"': '&quot;' }[c]));

/** Fichier GPX 1.1 (une trace, étapes en wpt) à partir de points [lat, lon]. */
export function gpx(points, { titre = 'Balade RuneRide', etapes = [], attribution = '' } = {}) {
  const wpt = etapes.map((e) => `  <wpt lat="${e.lat.toFixed(5)}" lon="${e.lon.toFixed(5)}"><name>${xml(e.nom || 'Étape')}</name></wpt>`).join('\n');
  const trk = points.map(([lat, lon]) => `      <trkpt lat="${lat.toFixed(5)}" lon="${lon.toFixed(5)}"/>`).join('\n');
  return `<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="RuneRide Raido" xmlns="http://www.topografix.com/GPX/1/1">
  <metadata><name>${xml(titre)}</name>${attribution ? `<copyright author="${xml(attribution)}"/>` : ''}</metadata>
${wpt ? `${wpt}\n` : ''}  <trk>
    <name>${xml(titre)}</name>
    <trkseg>
${trk}
    </trkseg>
  </trk>
</gpx>
`;
}

/** Sous-échantillonne `points` en `n` points répartis (premier et dernier conservés). */
export function echantillonner(points, n) {
  if (points.length <= n) return points.slice();
  const pas = (points.length - 1) / (n - 1);
  return Array.from({ length: n }, (_, k) => points[Math.round(k * pas)]);
}
