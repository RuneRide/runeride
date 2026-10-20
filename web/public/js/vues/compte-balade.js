import { get, put, del } from '../api.js';
import { h, toast, squelette } from '../dom.js';
import { erreurVue } from './commun.js';

// ---------- Compte : section « Balade » (préférences §7.3, partage des traces §9) ----------
const CLASSES = { autoroute: 'Autoroute', nationale: 'Route nationale', primaire: 'Route primaire', secondaire: 'Route secondaire', tertiaire: 'Route tertiaire', non_classee: 'Route non classée', residentielle: 'Rue résidentielle' };
const VITESSES_DEFAUT = { autoroute: 110, nationale: 80, primaire: 70, secondaire: 60, tertiaire: 50, non_classee: 45, residentielle: 30 };

const curseur = (id, libelle, sortie, champ) => h('div', { class: 'curseur' }, h('label', { for: id }, libelle, sortie), champ);

/** Contenu de la section ; `rafraichir()` relit l'API. Les valeurs affichées sont celles du serveur, jamais d'un cache local. */
export function sectionBalade() {
  const racine = h('div', {}, squelette('ligne'));

  async function rafraichir() {
    let p;
    try { p = await get('/api/balade/preferences'); } catch (e) { racine.replaceChildren(erreurVue(e) || ''); return; }
    racine.replaceChildren(formulaire(p));
  }

  function formulaire(p) {
    const perso = h('input', { type: 'checkbox', id: 'p-perso', checked: p.vitesses !== null });
    const vitesses = Object.fromEntries(p.classes.map((c) => [c, h('input', { type: 'number', id: `p-v-${c}`, min: 20, max: 130, step: 1, inputmode: 'numeric', value: String(p.vitesses?.[c] ?? VITESSES_DEFAUT[c] ?? 50) })]));
    const blocVitesses = h('div', { class: 'contenu-vitesses' }, p.classes.map((c) => h('label', { class: 'champ' }, h('span', {}, `${CLASSES[c] ?? c} (km/h)`), vitesses[c])));
    const pause = h('input', { type: 'range', id: 'p-pause', min: 0, max: 30, step: 5, value: p.pause_min_par_h });
    const sortiePause = h('output', { for: 'p-pause' });
    const pluie = h('input', { type: 'range', id: 'p-pluie', min: 0, max: 10, step: 0.5, value: p.seuils_meteo.pluie_mmh });
    const rafales = h('input', { type: 'range', id: 'p-raf', min: 20, max: 150, step: 5, value: p.seuils_meteo.rafales_kmh });
    const froid = h('input', { type: 'range', id: 'p-froid', min: -20, max: 25, step: 1, value: p.seuils_meteo.froid_c });
    const sPluie = h('output', { for: 'p-pluie' }); const sRaf = h('output', { for: 'p-raf' }); const sFroid = h('output', { for: 'p-froid' });
    const enregistrer = h('button', { class: 'btn primaire', type: 'submit' }, 'Enregistrer');
    const defauts = h('button', { class: 'btn', type: 'button' }, 'Valeurs par défaut');

    const maj = () => {
      blocVitesses.hidden = !perso.checked;
      sortiePause.textContent = `${pause.value} min/h`;
      sPluie.textContent = `${Number(pluie.value).toLocaleString('fr-FR')} mm/h`; sRaf.textContent = `${rafales.value} km/h`; sFroid.textContent = `${froid.value} °C`;
    };
    for (const el of [perso, pause, pluie, rafales, froid]) el.addEventListener('input', maj);
    maj();

    const form = h('form', {},
      h('p', { class: 'muted petit' }, 'Ces réglages sont envoyés au calculateur à chaque balade. Ils restent sur votre compte.'),
      h('label', { class: 'case' }, perso, 'Personnaliser les vitesses moyennes par type de route'), blocVitesses,
      curseur('p-pause', 'Pause par heure de route', sortiePause, pause),
      h('h3', { style: 'font-size:15px;margin:12px 0 4px' }, 'Météo à éviter'),
      curseur('p-pluie', 'Pluie à partir de', sPluie, pluie), curseur('p-raf', 'Rafales à partir de', sRaf, rafales), curseur('p-froid', 'Froid ressenti en dessous de', sFroid, froid),
      p.calibration ? h('p', { class: 'muted petit' }, 'Vos vitesses ont été mesurées sur vos trajets GeoRide et servent de repère.') : null,
      h('div', { class: 'actions-balade' }, enregistrer, defauts));
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault(); enregistrer.disabled = true;
      try {
        const corps = { pause_min_par_h: Number(pause.value), seuils_meteo: { pluie_mmh: Number(pluie.value), rafales_kmh: Number(rafales.value), froid_c: Number(froid.value) }, vitesses: null };
        if (perso.checked) corps.vitesses = Object.fromEntries(p.classes.map((c) => [c, Math.round(Number(vitesses[c].value))]));
        await put('/api/balade/preferences', corps);
        toast('ok', 'Préférences de balade enregistrées.');
        await rafraichir();
      } catch (e) { enregistrer.disabled = false; if (e.status !== 401) toast('erreur', e.message); }
    });
    defauts.addEventListener('click', async () => {
      defauts.disabled = true;
      try { await del('/api/balade/preferences'); toast('ok', 'Valeurs par défaut rétablies.'); await rafraichir(); } catch (e) { defauts.disabled = false; if (e.status !== 401) toast('erreur', e.message); }
    });

    // Partage des traces : opt-in séparé, effet immédiat, sans bouton « Enregistrer » (spec §9 : retrait par bascule).
    const partage = h('input', { type: 'checkbox', id: 'p-partage', checked: p.partage_traces });
    partage.addEventListener('change', async () => {
      const voulu = partage.checked; partage.disabled = true;
      try { await put('/api/balade/partage-traces', { actif: voulu }); toast('ok', voulu ? 'Partage anonyme activé.' : 'Partage retiré. Vos traces ne seront plus prises en compte au prochain recalcul.'); }
      catch (e) { partage.checked = !voulu; if (e.status !== 401) toast('erreur', e.message); }
      partage.disabled = false;
    });
    return h('div', {}, form,
      h('div', { class: 'zone-partage' },
        h('h3', { style: 'font-size:15px;margin:16px 0 4px' }, 'Score « motard »'),
        h('label', { class: 'case' }, partage, 'Contribuer, de façon anonyme, au score « motard » des routes'),
        h('p', { class: 'muted petit' }, 'Vos trajets GeoRide sont comptés par tronçon, sans lien avec votre compte, après suppression des 500 premiers et derniers mètres. Vous pouvez retirer votre accord à tout moment.')));
  }

  rafraichir();
  return racine;
}
