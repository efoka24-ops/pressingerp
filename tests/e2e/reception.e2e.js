'use strict';
// Parcours complet de la réception dans un vrai navigateur, sur un site réel :
// ouverture de caisse, création du client, saisie des articles (avec photo), acompte, étiquettes, ticket,
// encaissement du solde, reçu, clôture de caisse.
// ATTENTION : il enregistre de vraies données (un client « Test E2E », une commande, des paiements).
// Usage : BASE=http://… LOGIN=… PASS=… node reception.e2e.js
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = process.env.BASE || 'http://pressing-erp.trugroup.cm';
const SHOTS = path.join(__dirname, 'shots');
fs.mkdirSync(SHOTS, { recursive: true });
// Photo de test : un PNG valide de 1 pixel
const PHOTO = path.join(SHOTS, 'photo-test.png');
fs.writeFileSync(PHOTO, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'));

let failures = 0;
const check = (label, ok, detail) => { console.log((ok ? 'ok     ' : 'ÉCHEC  ') + label + (ok ? '' : ' — ' + (detail || ''))); if (!ok) failures++; };
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 1000 });
  const problems = [];
  page.on('pageerror', (e) => problems.push('JS : ' + e.message + ' @ ' + page.url()));
  page.on('response', (r) => { if (r.status() >= 400 && !r.url().endsWith('favicon.ico')) problems.push('HTTP ' + r.status() + ' ' + r.url()); });
  page.on('dialog', (d) => d.accept());
  await page.evaluateOnNewDocument(() => { window.print = () => {}; });   // la fenêtre d'impression bloquerait le navigateur sans écran
  const shot = (n) => page.screenshot({ path: path.join(SHOTS, n + '.png'), fullPage: true });
  const go = async (u) => { await page.goto(BASE + u, { waitUntil: 'networkidle2' }); };
  const submit = async (sel) => { await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click(sel)]); };
  const flash = () => page.$eval('.flash', (e) => e.innerText.trim()).catch(() => '');
  const text = () => page.$eval('body', (e) => e.innerText);

  // 1. Connexion
  await go('/login');
  await page.type('#login', process.env.LOGIN);
  await page.type('#password', process.env.PASS);
  await submit('button.primary');
  check('connexion', !page.url().includes('/login'), page.url());

  // 2. Caisse
  await go('/caisse');
  if (await page.$('form[action="/caisse/ouvrir"]')) {
    await page.$eval('form[action="/caisse/ouvrir"] [name=label]', (e) => { e.value = 'Caisse test E2E'; });
    await page.$eval('form[action="/caisse/ouvrir"] [name=opening_float]', (e) => { e.value = '0'; });
    await submit('form[action="/caisse/ouvrir"] button.primary');
  }
  await go('/caisse');
  check('caisse ouverte', !(await page.$('form[action="/caisse/ouvrir"]')), await flash());

  const RESUME = process.env.RESUME_ORDER;   // reprendre un parcours interrompu sur une commande déjà créée
  let orderId = RESUME || null, number = null;
  if (!RESUME) {
  // 3. Client
  const phone = '6' + String(Math.floor(10000000 + Math.random() * 89999999));
  await go('/clients/nouveau?retour=commande');
  await page.type('input[name=name]', 'Aïcha Test E2E');
  await page.type('input[name=phone]', phone);
  await submit('form[action="/clients"] button.primary, form[action="/clients"] button.btn.primary');
  console.log('   après création du client :', page.url(), '|', await flash());
  check('client créé', !(await flash()).toLowerCase().includes('invalide') && !page.url().endsWith('/clients/nouveau'), await flash());
  await shot('1-client');

  // 4. Commande : articles, photo, acompte
  let m = page.url().match(/client=(\d+)/) || page.url().match(/\/clients\/(\d+)/);
  const clientId = m ? m[1] : null;
  await go('/commandes/nouvelle' + (clientId ? '?client=' + clientId : ''));
  check('client choisi sur la commande', await page.$eval('#client-id', (e) => e.value).then((v) => v !== ''), 'le champ client_id est vide');
  const tiles = await page.$$('.tile[data-article]');
  await tiles[0].click();   // Chemise
  await tiles[1].click();   // Pantalon
  await wait(800);
  check('deux lignes ajoutées', (await page.$$('#lines tbody tr')).length === 2, String((await page.$$('#lines tbody tr')).length));
  // Une tache signalée sur la chemise rend la photo obligatoire
  await page.type('#lines tbody tr:first-child .dmg', 'tache de vin devant');
  await wait(700);
  check('bouton bloqué sans la photo obligatoire', await page.$eval('#submit', (e) => e.disabled), 'le bouton devrait être désactivé');
  console.log('   message :', await page.$eval('#blocker', (e) => e.innerText));
  const photo = await page.$('#lines tbody tr:first-child .photo-in');
  await photo.uploadFile(PHOTO);
  await wait(800);
  check('bouton actif une fois la photo jointe', !(await page.$eval('#submit', (e) => e.disabled)), await page.$eval('#blocker', (e) => e.innerText));
  const total = await page.$eval('#q-total', (e) => e.innerText);
  console.log('   total affiché :', total.replace(/\s+/g, ' '));
  await page.$eval('input[name=deposit]', (e) => { e.value = '1000'; });
  await shot('2-commande');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('#submit')]);
  const url = page.url();
  console.log('   après validation :', url, '|', await flash());
  check('commande enregistrée', /\/commandes\/\d+/.test(url), await flash());
  orderId = (url.match(/\/commandes\/(\d+)/) || [])[1];
  const body = await text();
  number = (body.match(/PR-\d{4}-\d{6}/) || [])[0];
  check('numéro de commande affiché', !!number, body.slice(0, 200));
  console.log('   commande :', number);
  await shot('3-fiche-commande');

  } else {
    await go('/commandes/' + RESUME);
    number = ((await text()).match(/PR-\d{4}-\d{6}/) || [])[0];
    console.log('   reprise sur la commande', number);
  }

  // 5. Étiquettes et ticket
  if (orderId) {
    await go('/commandes/' + orderId + '/etiquettes?motif=controle');
    const labels = await page.evaluate(() => ({ qr: document.querySelectorAll('canvas, img.qr, #qr canvas, .qrcode').length, codes: (document.body.innerText.match(/PR-\d{4}-\d{6}-\d{2}/g) || []).length }));
    check('étiquettes : un code par pièce', labels.codes >= 2, JSON.stringify(labels));
    await shot('4-etiquettes');
    await go('/commandes/' + orderId + '/ticket');
    const ticket = await text();
    check('ticket : numéro, total TTC et reste à payer', ticket.includes(number) && /Total/i.test(ticket) && /reste/i.test(ticket), ticket.slice(0, 200));
    await shot('5-ticket');

    // 6. Solde et reçu
    await go('/commandes/' + orderId);
    const payForm = await page.$('form[action="/commandes/' + orderId + '/paiement"]');
    check('formulaire d\'encaissement du solde présent', !!payForm);
    if (payForm) {
      await submit('form[action="/commandes/' + orderId + '/paiement"] button.primary');
      console.log('   après encaissement :', page.url(), '|', await flash());
      check('solde encaissé', /recu=\d+/.test(page.url()) || (await flash()).includes('Paiement'), await flash());
      const recu = (page.url().match(/recu=(\d+)/) || [])[1];
      if (recu) {
        await go('/paiements/' + recu + '/recu');
        const r = await text();
        check('reçu : numéro RC-, montant, mode', /RC-\d{4}-\d{6}/.test(r) && /FCFA/.test(r), r.slice(0, 200));
        await shot('6-recu');
      } else {
        check('lien du reçu après paiement', false, page.url());
      }
    }
  }

  // 7. Clôture de caisse sans écart
  await go('/caisse/cloture');
  const inputs = await page.$$eval('input.cnt', (els) => els.map((e) => ({ name: e.name, exp: e.placeholder })));
  for (const i of inputs) { await page.$eval('input[name="' + i.name + '"]', (e, v) => { e.value = v; e.dispatchEvent(new Event('input')); }, i.exp); }
  await wait(300);
  await submit('#close-btn');
  console.log('   clôture :', await flash());
  check('caisse clôturée sans écart', (await flash()).includes('sans écart'), await flash());

  console.log(problems.length ? '\nPROBLÈMES SIGNALÉS PAR LE NAVIGATEUR :\n  ' + problems.join('\n  ') : '\naucune erreur JavaScript ni réponse HTTP en erreur');
  console.log(failures ? failures + ' contrôle(s) en échec' : 'PARCOURS COMPLET RÉUSSI');
  await browser.close();
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error('ÉCHEC DU SCRIPT', e); process.exit(1); });
