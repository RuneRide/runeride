// Autonomie dans la vue Balade (Chrome headless, compte abonné) : stations simulées par interception de /api/ravitaillement,
// calcul par le vrai moteur ; vérifie le champ, les pleins affichés (prix, distance) et ce qui est enregistré.
import puppeteer from 'puppeteer';
import { readFileSync } from 'node:fs';
const BASE = 'http://127.0.0.1:8080';
const jeton = readFileSync(process.env.JETON_FICHIER || '/banc/jeton.txt', 'utf8').trim();
const erreurs = []; const journal = [];
const ok = (cond, msg) => { journal.push(`${cond ? 'OK ' : 'KO '} ${msg}`); if (!cond) erreurs.push(msg); };
const browser = await puppeteer.launch({ args: ['--no-sandbox', '--disable-setuid-sandbox'] });
const page = await browser.newPage();
await page.setViewport({ width: 1200, height: 900 });
page.on('pageerror', (e) => { erreurs.push(`pageerror : ${e.message}`); });
await page.evaluateOnNewDocument((j) => { localStorage.setItem('runeride.jeton', j); }, jeton);
// Stations simulées : grille de 2 km autour d'Annecy, prix variables, comme le ferait la route PHP.
const stations = [];
for (let i = -12; i <= 12; i++) for (let j = -17; j <= 17; j++) stations.push({ id: `s${i}_${j}`, marque: 'Banc', nom: `Station ${i},${j}`, ville: 'Essai', lat: 45.9 + i * 0.02, lon: 6.13 + j * 0.02, prix_litre: Math.round((1.70 + ((i * 7 + j * 13) % 30) / 100) * 1000) / 1000 });
let appelsStations = 0; let corpsRaido = null;
await page.setRequestInterception(true);
page.on('request', (req) => {
  const u = req.url();
  if (u.includes('/api/ravitaillement')) { appelsStations++; req.respond({ status: 200, contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: JSON.stringify({ carburant: 'sp95', rayon_km: 50, stations }) }); return; }
  if (u.includes(':8090/v1/balade') && req.method() === 'POST') corpsRaido = req.postData();
  req.continue();
});
const texte = async (sel) => page.$eval(sel, (e) => e.textContent).catch(() => null);
const attendreTexte = (sel, re, t = 20000) => page.waitForFunction((s, r) => { const e = document.querySelector(s); return e && new RegExp(r).test(e.textContent); }, { timeout: t }, sel, re.source);
const clicTexte = async (sel, t) => { const h = await page.evaluateHandle((s, txt) => [...document.querySelectorAll(s)].find((e) => e.textContent.trim().startsWith(txt)), sel, t); if (!h.asElement()) throw new Error(`bouton « ${t} » absent`); await h.asElement().evaluate((b) => { b.scrollIntoView({ block: 'center' }); b.click(); }); };
try {
await page.goto(`${BASE}/#/balade?lat=45.8992&lon=6.1294`, { waitUntil: 'domcontentloaded' });
await attendreTexte('.form-balade', /Abonné/);
ok(true, 'compte abonné reconnu');
await page.evaluate(() => document.querySelector('details.reglages:last-of-type')?.setAttribute('open', ''));
ok(await page.$eval('#b-autonomie', (e) => !e.disabled), 'champ d\'autonomie actif pour un abonné');
// grande boucle de 120 km (mode distance), autonomie 100 km, réservoir plein
await page.evaluate(() => { document.querySelector('input[name=type][value=boucle]').checked = true; document.querySelector('input[name=mode][value=distance]').checked = true; });
await page.$eval('#b-distance', (e) => { e.value = '120'; e.dispatchEvent(new Event('input', { bubbles: true })); });
await page.$eval('#b-autonomie', (e) => { e.value = '100'; e.dispatchEvent(new Event('input', { bubbles: true })); });
await attendreTexte('.form-balade', /Abonné|illimités/);
await clicTexte('button.btn.primaire', 'Calculer');
await attendreTexte('.resultat-balade', /distance/, 90000);
ok(appelsStations >= 1, `stations demandées à la route PHP (${appelsStations} appel)`);
const q = JSON.parse(corpsRaido || '{}');
ok(q.autonomie?.km === 100 && q.autonomie?.reservoir === 1, 'autonomie envoyée à Raido');
ok(Array.isArray(q.stations) && q.stations.length > 100 && q.stations.length <= 500, `stations envoyées : ${q.stations?.length}`);
ok(q.stations.every((s) => typeof s.lat === 'number' && typeof s.lon === 'number'), 'stations bien formées');
const km = parseFloat((await page.$eval('.resultat-balade .chiffre strong', (e) => e.textContent)).replace(',', '.'));
ok(km > 60, `balade calculée : ${km} km`);
const pleins = await page.$$eval('.pleins li', (l) => l.map((e) => e.textContent));
ok(pleins.length >= 1, `plein(s) affichés : ${pleins.join(' | ')}`);
ok(pleins.every((p) => /Plein à [\d,]+ km/.test(p) && /€\/L/.test(p)), 'chaque plein indique sa distance et son prix');
await page.screenshot({ path: '/banc/captures/a1-autonomie.png' });
// enregistrement : ni la liste des stations ni les tronçons connus dans les paramètres
let enregistre = null;
page.on('request', (r) => { if (r.url().endsWith('/api/balades') && r.method() === 'POST') enregistre = r.postData(); });
await clicTexte('.actions-balade button', 'Enregistrer');
await page.type('#b-titre', 'Balade avec pleins');
await page.keyboard.press('Enter');
await attendreTexte('.mes-balades', /Balade avec pleins/);
const p = JSON.parse(enregistre || '{}').params || {};
ok(p.autonomie?.km === 100 && p.stations === undefined && p.troncons_connus === undefined, 'paramètres enregistrés : autonomie gardée, sans la liste des stations');
} catch (e) { erreurs.push(`exception : ${e.message}`); await page.screenshot({ path: '/banc/captures/a-erreur.png' }).catch(() => {}); }
await browser.close();
console.log(journal.join('\n'));
console.log(erreurs.length ? `\n${erreurs.length} ÉCHEC(S) :\n- ${erreurs.join('\n- ')}` : '\nTOUT OK');
process.exit(erreurs.length ? 1 : 0);
