// Parcours de la vue Balade dans Chrome headless : U8 (petite boucle), grand itinéraire, enregistrement, partage, quota.
import puppeteer from 'puppeteer';
import { readFileSync } from 'node:fs';
const BASE = 'http://127.0.0.1:8080';
const jeton = readFileSync(process.env.JETON_FICHIER || '/banc/jeton.txt', 'utf8').trim();
const erreurs = []; const journal = [];
const ok = (cond, msg) => { journal.push(`${cond ? 'OK ' : 'KO '} ${msg}`); if (!cond) erreurs.push(msg); };
const browser = await puppeteer.launch({ args: ['--no-sandbox', '--disable-setuid-sandbox'] });
const page = await browser.newPage();
await page.setViewport({ width: 1200, height: 800 });
page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) journal.push(`console.${m.type()} : ${m.text()}`); });
page.on('pageerror', (e) => { erreurs.push(`pageerror : ${e.message}`); });
page.on('requestfailed', (r) => { if (!r.url().includes('unpkg') && !r.url().includes('openstreetmap') && !r.url().includes('carto.runeride')) journal.push(`requête échouée : ${r.url()} ${r.failure()?.errorText}`); });
await page.evaluateOnNewDocument((j) => { localStorage.setItem('runeride.jeton', j); }, jeton);
const texte = async (sel) => page.$eval(sel, (e) => e.textContent).catch(() => null);
const attendreTexte = (sel, re, t = 20000) => page.waitForFunction((s, r) => { const e = document.querySelector(s); return e && new RegExp(r).test(e.textContent); }, { timeout: t }, sel, re.source);
const clicTexte = async (sel, t) => { const h = await page.evaluateHandle((s, txt) => [...document.querySelectorAll(s)].find((e) => e.textContent.trim().startsWith(txt)), sel, t); if (!h.asElement()) throw new Error(`bouton « ${t} » absent`); await h.asElement().evaluate((b) => { b.scrollIntoView({ block: 'center' }); b.click(); }); };

try {
// 1. Ouverture avec un départ dans l'adresse (Grenoble)
await page.goto(`${BASE}/#/balade?lat=45.1885&lon=5.7245`, { waitUntil: 'domcontentloaded' });
await attendreTexte('.form-balade', /Grand itinéraire : 3 sur 3/);
ok(true, 'vue Balade affichée, quota 3/3 lu');
ok((await texte('.position-etat span')).includes('45,1885'), 'départ pris dans l\'adresse');
ok(await page.$('#nav a[data-route=balade].actif'), 'onglet Balade actif');
await page.screenshot({ path: '/banc/captures/01-balade.png' });

// 2. U8 : petite boucle 25 km (gratuite), calcul via le faux Raido
await clicTexte('button.puce', 'Petite boucle 25 km');
await attendreTexte('.form-balade', /Petite boucle \(≤ 25 km\) : gratuite/);
await clicTexte('button.btn.primaire', 'Calculer');
await attendreTexte('.resultat-balade', /distance/, 60000);
const km = await page.$eval('.resultat-balade .chiffre strong', (e) => e.textContent);
ok(/km/.test(km) && parseFloat(km.replace(',', '.')) <= 30, `petite boucle calculée : ${km}`);
ok(await page.$('.leaflet-overlay-pane path'), 'tracé dessiné sur la carte');
await attendreTexte('.form-balade', /Petite boucle/);
ok((await texte('#b-quota')).includes('gratuite'), 'quota inchangé après une petite boucle');
await page.screenshot({ path: '/banc/captures/02-petite-boucle.png' });

// 3. Enregistrer puis partager
await clicTexte('.actions-balade button', 'Enregistrer');
await page.type('#b-titre', 'Boucle du banc');
await page.keyboard.press('Enter');
await attendreTexte('.mes-balades', /Boucle du banc/);
ok(true, 'balade enregistrée et listée');
ok((await texte('.mes-balades .titre-section')).includes('1 / 5'), 'compteur 1 / 5 en gratuit');
await clicTexte('.actions-balade button', 'Partager');
await attendreTexte('.resultat-balade', /Lien public/);
const lien = await page.$eval('.resultat-balade .bandeau.ok a', (a) => a.href);
ok(/#\/partage\?cle=[A-Za-z0-9_-]{22}$/.test(lien), `lien public : ${lien}`);
await page.screenshot({ path: '/banc/captures/03-enregistree-partagee.png' });

// 4. Lecture publique du lien, sans jeton
const ctxPublic = await browser.createBrowserContext(); // stockage local séparé : pas de jeton
const pub = await ctxPublic.newPage();
await pub.setViewport({ width: 1200, height: 800 });
pub.on('pageerror', (e) => erreurs.push(`pageerror (partage) : ${e.message}`));
await pub.goto(lien, { waitUntil: 'domcontentloaded' });
await pub.waitForFunction(() => /Boucle du banc/.test(document.querySelector('.detail h2')?.textContent || ''), { timeout: 20000 });
ok(await pub.$('.leaflet-overlay-pane path'), 'balade partagée lisible sans compte, tracé dessiné');
ok(await pub.$('#nav[hidden]'), 'navigation masquée hors connexion');
await pub.screenshot({ path: '/banc/captures/04-partage-public.png' });
await pub.goto(`${BASE}/#/partage?cle=inconnue-inconnue-inconnue`, { waitUntil: 'domcontentloaded' });
await pub.waitForFunction(() => /introuvable/.test(document.body.textContent), { timeout: 10000 });
ok(true, 'clé inconnue : message « introuvable »');
await pub.close(); await ctxPublic.close();

// 5. Grand itinéraire : 100 km, décompte 3 → 2, puis épuisement
await page.$eval('input[name=mode][value=distance]', (e) => { e.checked = true; e.dispatchEvent(new Event('change', { bubbles: true })); });
await page.$eval('#b-distance', (e) => { e.value = '100'; e.dispatchEvent(new Event('input', { bubbles: true })); });
await attendreTexte('.form-balade', /Grand itinéraire : 3 sur 3/);
await clicTexte('button.btn.primaire', 'Calculer');
await attendreTexte('.form-balade', /Grand itinéraire : 2 sur 3/, 60000);
await attendreTexte('.resultat-balade', /distance/, 60000);
const km2 = await page.$eval('.resultat-balade .chiffre strong', (e) => e.textContent);
ok(parseFloat(km2.replace(/\s/g, '').replace(',', '.')) > 60, `grand itinéraire calculé : ${km2}`);
await page.screenshot({ path: '/banc/captures/05-grand.png' });
// Autre proposition : même jeton, pas de décompte
const variantes = await page.$eval('.actions-balade button', (b) => !b.disabled);
if (variantes) { await clicTexte('.actions-balade button', 'Autre proposition'); await attendreTexte('.resultat-balade', /Calcul en cours/, 5000).catch(() => {}); await attendreTexte('.resultat-balade', /distance/, 60000); ok((await texte('#b-quota')).includes('2 sur 3'), 'autre proposition sans décompte'); }
else journal.push('une seule variante : « Autre proposition » désactivé');
// Deux autres grands calculs par rechargement (nouveau jeton à chaque vue), puis quota épuisé
for (const attendu of ['1 sur 3', '0 sur 3']) {
  await page.reload({ waitUntil: 'domcontentloaded' });
  await attendreTexte('.form-balade', /Grand itinéraire/);
  await clicTexte('button.btn.primaire', 'Calculer');
  await attendreTexte('.form-balade', new RegExp(attendu), 60000);
  await attendreTexte('.resultat-balade', /distance/, 60000);
}
ok((await texte('#b-quota')).includes('0 sur 3'), 'quota 0 sur 3 après trois grands calculs');
ok(await page.$('button.btn.primaire[disabled]'), 'bouton Calculer désactivé à quota épuisé');
await page.reload({ waitUntil: 'domcontentloaded' });
await attendreTexte('.form-balade', /0 sur 3/);
ok(await page.$('button.btn.primaire[disabled]'), 'au rechargement, Calculer reste désactivé pour un grand itinéraire');
await clicTexte('button.puce', 'Petite boucle 25 km');
await attendreTexte('.form-balade', /gratuite et illimitée/);
ok(!(await page.$('button.btn.primaire[disabled]')), 'la petite boucle reste possible');
await page.screenshot({ path: '/banc/captures/06-quota-epuise.png' });

// 6. Mobile : feuille et écran étroit
await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
await page.reload({ waitUntil: 'domcontentloaded' });
await attendreTexte('.form-balade', /Petite boucle|Grand itinéraire/);
await page.screenshot({ path: '/banc/captures/07-mobile.png' });

} catch (e) { erreurs.push(`exception : ${e.message}`); await page.screenshot({ path: '/banc/captures/99-erreur.png' }); journal.push('msg : ' + (await page.$eval('#vue', (v) => v.innerText.slice(0, 600)).catch(() => '?'))); }
await browser.close();
console.log(journal.join('\n'));
console.log(erreurs.length ? `\n${erreurs.length} ÉCHEC(S) :\n${erreurs.join('\n')}` : '\nTOUT OK');
process.exit(erreurs.length ? 1 : 0);
