// Étapes dans la vue Balade (Chrome headless) : pose sur la carte, ordre, retrait, calcul par le vrai moteur, enregistrement et rechargement.
import puppeteer from 'puppeteer';
import { readFileSync } from 'node:fs';
const BASE = 'http://127.0.0.1:8080';
const jeton = readFileSync(process.env.JETON_FICHIER || '/banc/jeton.txt', 'utf8').trim();
const erreurs = []; const journal = [];
const ok = (cond, msg) => { journal.push(`${cond ? 'OK ' : 'KO '} ${msg}`); if (!cond) erreurs.push(msg); };
const browser = await puppeteer.launch({ args: ['--no-sandbox', '--disable-setuid-sandbox'] });
const page = await browser.newPage();
await page.setViewport({ width: 1200, height: 800 });
page.on('console', (m) => { if (['error'].includes(m.type())) journal.push(`console.${m.type()} : ${m.text()}`); });
page.on('pageerror', (e) => { erreurs.push(`pageerror : ${e.message}`); });
await page.evaluateOnNewDocument((j) => { localStorage.setItem('runeride.jeton', j); }, jeton);
const texte = async (sel) => page.$eval(sel, (e) => e.textContent).catch(() => null);
const attendreTexte = (sel, re, t = 20000) => page.waitForFunction((s, r) => { const e = document.querySelector(s); return e && new RegExp(r).test(e.textContent); }, { timeout: t }, sel, re.source);
const clicTexte = async (sel, t) => { const h = await page.evaluateHandle((s, txt) => [...document.querySelectorAll(s)].find((e) => e.textContent.trim().startsWith(txt)), sel, t); if (!h.asElement()) throw new Error(`bouton « ${t} » absent`); await h.asElement().evaluate((b) => { b.scrollIntoView({ block: 'center' }); b.click(); }); };
const nbEtapes = () => page.$$eval('.ligne-etape', (l) => l.length);
// clic sur la carte à un décalage (px) du centre de la zone carte
const clicCarte = async (dx, dy) => { const r = await page.$eval('.leaflet-container', (e) => { const b = e.getBoundingClientRect(); return { x: b.x + b.width / 2, y: b.y + b.height / 2 }; }); await page.mouse.click(r.x + dx, r.y + dy); };

try {
await page.goto(`${BASE}/#/balade?lat=45.8992&lon=6.1294`, { waitUntil: 'domcontentloaded' });
await attendreTexte('.form-balade', /Petite boucle|Grand itinéraire/);
await page.waitForSelector('.leaflet-container');
await new Promise((r) => setTimeout(r, 1500));
ok(await nbEtapes() === 0, 'aucune étape au départ');

// 1. Poser deux étapes sur la carte
for (const [dx, dy] of [[-120, -60], [90, 70]]) {
  await clicTexte('button.puce', 'Ajouter une étape');
  await clicCarte(dx, dy);
  await new Promise((r) => setTimeout(r, 300));
}
ok(await nbEtapes() === 2, 'deux étapes posées sur la carte');
ok((await texte('.ligne-etape:first-child')).includes('Étape 1'), 'libellé « Étape 1 »');
const premiere = await texte('.ligne-etape:nth-child(1) .mono');
const seconde = await texte('.ligne-etape:nth-child(2) .mono');
ok(await page.$$eval('.leaflet-interactive', (l) => l.length) >= 2, 'étapes dessinées sur la carte');
ok((await texte('.form-balade')).includes('Grand itinéraire'), 'avec des étapes, le calcul est un grand itinéraire');
ok(await page.$eval('#b-duree', (e) => e.closest('[hidden]') !== null || e.offsetParent === null), 'curseur de durée masqué avec des étapes');

// 2. Réordonner : ▼ sur l'étape 1 → ordre inversé ; ▲ sur l'étape 2 → retour
await page.click('.ligne-etape:nth-child(1) button[aria-label^="Descendre"]');
ok(await texte('.ligne-etape:nth-child(1) .mono') === seconde && await texte('.ligne-etape:nth-child(2) .mono') === premiere, 'ordre inversé par ▼');
await page.click('.ligne-etape:nth-child(2) button[aria-label^="Monter"]');
ok(await texte('.ligne-etape:nth-child(1) .mono') === premiere, 'ordre rétabli par ▲');
ok(await page.$eval('.ligne-etape:nth-child(1) button[aria-label^="Monter"]', (b) => b.disabled), '▲ désactivé sur la première');

// 3. Retirer puis reposer
await page.click('.ligne-etape:nth-child(2) button[aria-label^="Retirer"]');
ok(await nbEtapes() === 1, 'étape retirée');
await clicTexte('button.puce', 'Ajouter une étape');
await clicCarte(90, 70);
await new Promise((r) => setTimeout(r, 300));
ok(await nbEtapes() === 2, 'étape reposée');
await page.screenshot({ path: '/banc/captures/e1-etapes.png' });

// 4. Calcul par le vrai moteur
await clicTexte('button.btn.primaire', 'Calculer');
await attendreTexte('.resultat-balade', /distance/, 60000);
const km = parseFloat((await page.$eval('.resultat-balade .chiffre strong', (e) => e.textContent)).replace(',', '.'));
ok(km > 5, `parcours calculé par les étapes : ${km} km`);
ok(/à [\d,]+ km du départ/.test(await texte('.ligne-etape:nth-child(1)')) && /à [\d,]+ km du départ/.test(await texte('.ligne-etape:nth-child(2)')), 'distance au passage affichée pour chaque étape');
await page.screenshot({ path: '/banc/captures/e2-resultat.png' });

// 5. Enregistrer, vider les étapes, recharger la balade : les étapes reviennent
await clicTexte('.actions-balade button', 'Enregistrer');
await page.type('#b-titre', 'Balade à étapes');
await page.keyboard.press('Enter');
await attendreTexte('.mes-balades', /Balade à étapes/);
await page.click('.ligne-etape:nth-child(1) button[aria-label^="Retirer"]');
await page.click('.ligne-etape:nth-child(1) button[aria-label^="Retirer"]');
ok(await nbEtapes() === 0, 'étapes retirées avant rechargement');
await clicTexte('.mes-balades button.trajet', 'Balade à étapes');
await attendreTexte('.resultat-balade', /distance/, 20000);
await new Promise((r) => setTimeout(r, 500));
ok(await nbEtapes() === 2, 'balade rechargée : ses deux étapes reviennent');
ok(await texte('.ligne-etape:nth-child(1) .mono') === premiere, 'dans le même ordre');
} catch (e) { erreurs.push(`exception : ${e.message}`); await page.screenshot({ path: '/banc/captures/e-erreur.png' }).catch(() => {}); }
await browser.close();
console.log(journal.join('\n'));
console.log(erreurs.length ? `\n${erreurs.length} ÉCHEC(S) :\n- ${erreurs.join('\n- ')}` : '\nTOUT OK');
process.exit(erreurs.length ? 1 : 0);
