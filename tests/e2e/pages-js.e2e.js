'use strict';
// Ouvre les pages principales dans un vrai navigateur et signale toute erreur JavaScript ou réponse HTTP en erreur.
// Ne crée rien. Usage : BASE=http://… LOGIN=… PASS=… node pages-js.e2e.js
const puppeteer = require('puppeteer-core');
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = process.env.BASE || 'http://pressing-erp.trugroup.cm';
const PAGES = (process.env.PAGES || '/cockpit,/comptoir,/commandes,/commandes/nouvelle,/commandes/non-retirees,/clients,/clients/nouveau,/tracabilite,/scan,/production,/qualite,/caisse,/caisse/cloture,/livraisons,/livraisons/collecte,/commercial,/commercial/factures,/commercial/devis,/commercial/devis/nouveau,/recouvrement,/marketing,/stocks,/bi,/tarifs,/hors-ligne,/alertes,/rapports/journalier,/rapports/mensuel,/admin,/admin/utilisateurs,/admin/agences,/admin/demandes,/admin/parametres,/admin/messages,/admin/alertes,/admin/postes,/admin/audit,/admin/sauvegardes,/recherche?q=PR-2026,/suivi,/ouvrir-un-pressing,/').split(',');

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 900 });
  await page.evaluateOnNewDocument(() => { window.print = () => {}; });
  page.on('dialog', (d) => d.dismiss());
  let current = '';
  const bad = [];
  page.on('pageerror', (e) => bad.push(current + '  JS : ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource: the server responded with a status of 404/.test(m.text())) bad.push(current + '  console : ' + m.text()); });
  page.on('response', (r) => { if (r.status() >= 500) bad.push(current + '  HTTP ' + r.status() + ' ' + r.url()); });

  await page.goto(BASE + '/login', { waitUntil: 'networkidle2' });
  await page.type('#login', process.env.LOGIN);
  await page.type('#password', process.env.PASS);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button.primary')]);
  for (const u of PAGES) {
    current = u;
    try {
      const r = await page.goto(BASE + u, { waitUntil: 'networkidle2', timeout: 30000 });
      console.log((r && r.status() < 400 ? 'ok     ' : 'ÉCHEC  ') + u + (r ? ' (' + r.status() + ')' : ''));
    } catch (e) { bad.push(u + '  navigation : ' + e.message); console.log('ÉCHEC  ' + u); }
  }
  console.log(bad.length ? '\nERREURS :\n  ' + bad.join('\n  ') : '\nAucune erreur JavaScript, aucune erreur serveur');
  await browser.close();
  process.exit(bad.length ? 1 : 0);
})().catch((e) => { console.error('ÉCHEC DU SCRIPT', e); process.exit(1); });
