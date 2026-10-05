'use strict';
/*
 * Test de bout en bout de la réception hors-ligne dans un vrai Chrome :
 * vrai service worker, vraie IndexedDB, vrai réseau coupé (CDP), faux serveur local pour l'API.
 * La logique serveur est couverte par les tests PHP ; ici on vérifie le comportement du navigateur.
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const puppeteer = require('puppeteer-core');

const PUB = path.join(__dirname, '..', '..', 'public');
const HTML = execFileSync('php', [path.join(__dirname, 'render.php')]).toString();
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const state = { csrfCalls: 0, dataCalls: 0, synced: [], badHeaders: 0, online: true };
const SNAP = {
  ok: true, snapshot_at: '2026-10-05 08:00:00', server_time: Math.floor(Date.now() / 1000),
  agency: { id: 2, name: 'Akwa (Douala)' }, user: { id: 5, name: 'Fatou Tchamba' },
  articles: [{ id: 1, name: 'Chemise', unit: 'piece', fragile: 0 }, { id: 2, name: 'Robe de soirée', unit: 'piece', fragile: 1 }],
  treatments: [{ id: 1, label: 'Nettoyage complet' }, { id: 5, label: 'Repassage seul' }],
  prices: { normal: { 1: 1000, 2: 6000 }, vip: { 1: 900, 2: 5500 }, business: {} },
  clients: [
    { id: 11, code: 'CL-2026-000011', name: 'Marie Ngo Bayiha', phone: '+237670123456', phone_fmt: '+237 6 70 12 34 56', type: 'particulier', is_vip: 0, preferences: 'Sans amidon', next_nth: false, blocked: null },
    { id: 12, code: 'CL-2026-000012', name: 'Hôtel Ibis', phone: '+237222334455', phone_fmt: '+237 2 22 33 44 55', type: 'pro', is_vip: 0, preferences: '', next_nth: false, blocked: 'Client en compte : réception hors-ligne impossible' },
  ],
  levels: [{ value: 'standard', label: 'Standard', surcharge_pct: 0, delay_hours: 72 }, { value: 'express', label: 'Express', surcharge_pct: 50, delay_hours: 24 }, { value: 'vip', label: 'VIP', surcharge_pct: 20, delay_hours: 48 }],
  settings: { vat_rate: '19.25', photo_threshold: 50000, delivery_fee: 1500, nth_pct: 10, company: 'Pressing Test', niu: 'M0123456', app_url: 'http://localhost' },
};
let block = { year: 2026, from: 500, to: 504 };   // volontairement petite pour tester l'épuisement

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x');
  const json = (o, code = 200) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
  if (url.pathname === '/hors-ligne') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); return res.end(HTML); }
  if (url.pathname === '/sw.js' || url.pathname.startsWith('/assets/')) {
    const f = path.join(PUB, url.pathname);
    if (fs.existsSync(f)) {
      const ct = f.endsWith('.js') ? 'application/javascript' : f.endsWith('.css') ? 'text/css' : 'application/octet-stream';
      res.writeHead(200, { 'Content-Type': ct }); return res.end(fs.readFileSync(f));
    }
  }
  if (url.pathname === '/api/hors-ligne/csrf') { state.csrfCalls++; return json({ ok: true, token: 'tok-123', user: 5 }); }
  if (req.method === 'POST' && url.pathname.startsWith('/api/hors-ligne/')) {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const body = Buffer.concat(chunks).toString('latin1');
      if (req.headers['x-csrf-token'] !== 'tok-123') { state.badHeaders++; return json({ ok: false, error: 'csrf' }, 419); }
      if (url.pathname.endsWith('/poste')) { return json({ ok: true, id: 1, token: 'station-token-xyz' }); }
      if (!req.headers['x-station']) { state.badHeaders++; return json({ ok: false, error: 'poste' }, 403); }
      if (url.pathname.endsWith('/donnees')) {
        state.dataCalls++;
        const out = Object.assign({}, SNAP, { block: state.dataCalls === 1 ? block : null, unused: 5 });
        return json(out);
      }
      if (url.pathname.endsWith('/commandes')) {
        if (!state.online) { req.socket.destroy(); return; }
        const m = /name="payload"\r\n\r\n([\s\S]*?)\r\n--/.exec(body);
        const p = JSON.parse(Buffer.from(m[1], 'latin1').toString('utf8'));
        state.synced.push(p);
        return json({ ok: true, status: 'created', number: p.number, order_id: 9000 + state.synced.length, message: null });
      }
    });
    return;
  }
  res.writeHead(404); res.end('not found');
});

let failures = 0;
const check = (label, ok, detail) => { console.log((ok ? 'ok    ' : 'ECHEC ') + label + (ok ? '' : ' — ' + detail)); if (!ok) failures++; };
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
async function until(fn, ms = 8000) { const t = Date.now(); while (Date.now() - t < ms) { if (await fn()) return true; await wait(100); } return false; }

(async () => {
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const base = 'http://localhost:' + server.address().port;
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', protocolTimeout: 20000, args: ['--no-sandbox', '--host-resolver-rules=MAP localhost 127.0.0.1'] });
  const popups = [];
  const page = await browser.newPage();
  browser.on('targetcreated', async (t) => { if (t.type() === 'page') { const p = await t.page(); if (p && p !== page) popups.push(p); } });
  // La boîte d'impression bloquerait Chrome sans interface : on neutralise print() dans les fenêtres ouvertes par la page
  await page.evaluateOnNewDocument(() => { window.__noPrint = true; window.__printed = []; window.open = function () { return { document: { open() {}, close() {}, write(h) { window.__printed.push(h); } } }; }; });
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('dialog', (d) => d.accept());

  try {
    // 1) Poste non autorisé : écran d'autorisation, puis autorisation et chargement des données
    await page.goto(base + '/hors-ligne', { waitUntil: 'networkidle0' });
    check('poste non autorisé : écran d\'autorisation visible', await page.$eval('#setup', (e) => !e.hidden), '');
    await page.type('#station-label', 'Comptoir Akwa — tablette 1');
    await page.click('#btn-register');
    check('autorisation : jeton mémorisé, données chargées', await until(async () => await page.$eval('#app', (e) => !e.hidden)), 'app masquée');
    check('autorisation : le jeton est dans le navigateur', (await page.evaluate(() => localStorage.getItem('pressing.station'))) === 'station-token-xyz', '');
    check('données : 1 plage de 5 numéros disponible', /5 numéro/.test(await page.$eval('#numbers', (e) => e.textContent)), await page.$eval('#numbers', (e) => e.textContent));
    check('service worker actif (localhost = contexte sécurisé)', await until(async () => await page.evaluate(async () => !!(await navigator.serviceWorker.getRegistration()) && !!navigator.serviceWorker.controller || !!(await navigator.serviceWorker.ready).active)), '');
    await page.reload({ waitUntil: 'networkidle0' });   // la page passe sous le contrôle du service worker

    // 2) Coupure réseau réelle, rechargement de la page : elle doit s'afficher depuis le cache
    state.online = false;
    await page.setOfflineMode(true);
    await page.reload({ waitUntil: 'domcontentloaded' });
    check('hors réseau : la page se recharge depuis le cache', await until(async () => await page.$eval('#app', (e) => !e.hidden)), 'page indisponible hors réseau');
    check('hors réseau : l\'indicateur affiche Hors-ligne', /Hors-ligne/.test(await page.$eval('#net-label', (e) => e.textContent)), '');

    // 3) Saisie d'une commande sans réseau : client inconnu invalide refusé, puis valide
    await page.evaluate(() => { document.querySelector('details').open = true; });
    await page.type('#nc-name', 'Anonyme');
    await page.type('#nc-phone', '670');
    await page.click('#nc-add');
    check('client non identifiable refusé', /identifié|invalide/.test(await page.$eval('#msg', (e) => e.textContent)), await page.$eval('#msg', (e) => e.textContent));
    await page.$eval('#nc-name', (e) => e.value = ''); await page.$eval('#nc-phone', (e) => e.value = '');
    await page.type('#client-search', 'ngo bay');
    await until(async () => !!(await page.$('#client-results button')));
    await page.click('#client-results button');
    check('client choisi dans les données locales', /Marie Ngo Bayiha/.test(await page.$eval('#cp-name', (e) => e.textContent)), '');
    const tiles = await page.$$('#tiles button');
    await tiles[0].click();                                  // Chemise
    check('total calculé hors réseau (1 000 FCFA)', /1\s?000/.test(await page.$eval('#q-total', (e) => e.textContent)), await page.$eval('#q-total', (e) => e.textContent));
    await tiles[1].click();                                  // Robe fragile : photo obligatoire
    check('pièce fragile : validation bloquée sans photo', await page.$eval('#submit', (e) => e.disabled), '');
    const rowsBefore = (await page.$$('#lines tbody tr')).length;
    await (await page.$$('#lines tbody tr button'))[1].click();   // on retire la robe
    check('ligne retirée', (await page.$$('#lines tbody tr')).length === rowsBefore - 1, '');
    check('validation possible', await until(async () => !(await page.$eval('#submit', (e) => e.disabled))), await page.$eval('#blocker', (e) => e.textContent));
    await page.click('#submit');
    check('commande 1 enregistrée localement avec le premier numéro de la plage', await until(async () => /PR-2026-000500/.test(await page.$eval('#queue', (e) => e.textContent))), await page.$eval('#queue', (e) => e.textContent));
    await until(async () => (await page.evaluate(() => window.__printed.length)) > 0);
    const ticketHtml = (await page.evaluate(() => window.__printed))[0] || '';
    check('ticket imprimable généré hors réseau (copie hors-ligne, TVA, NIU)', /COPIE HORS-LIGNE/.test(ticketHtml) && /PR-2026-000500/.test(ticketHtml) && /M0123456/.test(ticketHtml) && /TVA 19.25/.test(ticketHtml), ticketHtml.slice(0, 200));

    // Les fenêtres d'impression passent devant : on les ferme et on ramène la page de saisie au premier plan
    for (const p of popups.splice(0)) { await p.close().catch(() => {}); }
    await page.bringToFront();

    // 4) Deuxième commande : numéro suivant, jamais de doublon
    await tiles[0].click();
    await page.type('#client-search', 'ngo bay'); await until(async () => !!(await page.$('#client-results button'))); await page.click('#client-results button');
    await until(async () => !(await page.$eval('#submit', (e) => e.disabled)));
    await page.click('#submit');
    check('commande 2 : numéro suivant PR-2026-000501', await until(async () => /PR-2026-000501/.test(await page.$eval('#queue', (e) => e.textContent))), '');
    const numbers = await page.evaluate(() => Array.from(document.querySelectorAll('#queue tr td:first-child')).map((t) => t.textContent));
    check('aucun numéro en double', new Set(numbers).size === numbers.length && numbers.length === 2, JSON.stringify(numbers));
    check('2 commandes en attente affichées', /2/.test(await page.$eval('#pending-count', (e) => e.textContent)), await page.$eval('#pending-count', (e) => e.textContent));
    check('rien n\'a été envoyé hors réseau', state.synced.length === 0, String(state.synced.length));

    // 5) Rechargement toujours hors réseau : les commandes survivent (IndexedDB)
    await page.reload({ waitUntil: 'domcontentloaded' });
    check('les commandes survivent au rechargement hors réseau', await until(async () => /PR-2026-000501/.test(await page.$eval('#queue', (e) => e.textContent))), '');

    // 6) Retour du réseau : synchronisation automatique, dans l'ordre, une seule fois chacune
    state.online = true;
    await page.setOfflineMode(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    check('synchronisation automatique au retour du réseau', await until(async () => state.synced.length === 2, 12000), 'envoyées: ' + state.synced.length);
    check('ordre de saisie respecté', state.synced.map((p) => p.number).join() === 'PR-2026-000500,PR-2026-000501', state.synced.map((p) => p.number).join());
    const p0 = state.synced[0];
    check('charge utile complète (client, lignes, jeton de suivi, total, horodatage)', p0.client.id === 11 && p0.lines.length === 1 && /^[0-9a-f]{32}$/.test(p0.tracking_token) && p0.total === 1000 && /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(p0.created_at), JSON.stringify(p0));
    check('les appels portent le jeton CSRF frais et le poste', state.badHeaders === 0 && state.csrfCalls > 0, 'badHeaders=' + state.badHeaders);
    check('commandes marquées synchronisées', await until(async () => (await page.$$eval('#queue .st-synced', (n) => n.length)) === 2), await page.$eval('#queue', (e) => e.textContent));
    await wait(1500);
    check('pas de renvoi en double', state.synced.length === 2, String(state.synced.length));
    // 7) Plage épuisée : après 5 commandes, la 6e est refusée proprement
    for (let i = 0; i < 3; i++) {
      await page.evaluate(() => Array.from(document.querySelectorAll('#tiles button'))[0].click());
      await page.type('#client-search', 'ngo bay'); await until(async () => !!(await page.$('#client-results button'))); await page.click('#client-results button');
      await until(async () => !(await page.$eval('#submit', (e) => e.disabled)));
      await page.click('#submit');
      await until(async () => (await page.$$('#queue tr')).length === 3 + i, 5000);
      for (const p of popups.splice(0)) { await p.close().catch(() => {}); }
      await page.bringToFront();
    }
    await page.evaluate(() => Array.from(document.querySelectorAll('#tiles button'))[0].click());
    await page.type('#client-search', 'ngo bay'); await until(async () => !!(await page.$('#client-results button'))); await page.click('#client-results button');
    await wait(500);
    check('plage épuisée : validation impossible avec un message clair', (await page.$eval('#submit', (e) => e.disabled)) && /épuisée/.test(await page.$eval('#blocker', (e) => e.textContent)), await page.$eval('#blocker', (e) => e.textContent));

    check('aucune erreur JavaScript', errors.length === 0, errors.join(' | '));
  } catch (e) {
    console.log('ECHEC exception — ' + e.message); failures++;
  } finally {
    await Promise.race([browser.close(), wait(8000)]);
    server.close();
  }
  console.log(failures ? failures + ' échec(s)' : 'Tous les contrôles passent');
  process.exit(failures ? 1 : 0);
})();
