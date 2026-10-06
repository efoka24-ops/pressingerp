'use strict';
// Sonde de la réception sur le site en ligne, sans rien enregistrer : erreurs JavaScript, recherche client, ajout d'articles, devis.
// Usage : BASE=http://… LOGIN=… PASS=… node probe-reception.js
const puppeteer = require('puppeteer-core');
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = process.env.BASE || 'http://pressing-erp.trugroup.cm';

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 900 });
  const problems = [];
  page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') problems.push('console: ' + m.text()); });
  page.on('requestfailed', (r) => problems.push('requestfailed: ' + r.url() + ' ' + (r.failure() || {}).errorText));
  page.on('response', (r) => { if (r.status() >= 400) problems.push('http ' + r.status() + ' ' + r.url()); });

  await page.goto(BASE + '/login', { waitUntil: 'networkidle2' });
  await page.type('#login', process.env.LOGIN);
  await page.type('#password', process.env.PASS);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button.primary')]);
  console.log('après connexion :', page.url());
  await page.goto(BASE + '/commandes/nouvelle', { waitUntil: 'networkidle2' });
  console.log('page :', page.url(), '· titre :', await page.title());
  console.log('tuiles articles :', await page.$$eval('.tile[data-article]', (e) => e.length));
  console.log('formulaire présent :', !!(await page.$('#order-form')));

  // recherche client
  await page.type('#client-search', 'Bello');
  await new Promise((r) => setTimeout(r, 900));
  console.log('résultats client :', await page.$$eval('#client-results button', (e) => e.map((b) => b.innerText.replace(/\s+/g, ' ').slice(0, 60))));

  // ajout d'un article
  await page.click('.tile[data-article]');
  await new Promise((r) => setTimeout(r, 900));
  console.log('lignes après clic :', await page.$$eval('#lines tbody tr', (e) => e.length));
  console.log('total affiché :', await page.$eval('#q-total', (e) => e.innerText).catch(() => 'absent'));
  console.log('bouton de validation :', await page.$$eval('#order-form button', (b) => b.map((x) => x.innerText.trim() + (x.disabled ? ' [désactivé]' : '')).filter(Boolean)));
  console.log(problems.length ? 'PROBLÈMES :\n  ' + problems.join('\n  ') : 'aucune erreur JavaScript ni réseau');
  await browser.close();
})().catch((e) => { console.error('ÉCHEC', e); process.exit(1); });
