'use strict';
// Suite du parcours dans un vrai navigateur : atelier (prise en charge et fin d'étape au scan), contrôle qualité, emballage,
// puis remise de la commande au comptoir. Reprend la commande créée par reception.e2e.js.
// ATTENTION : écrit de vraies données (étapes, contrôle, remise) sur la commande indiquée.
// Usage : ORDER=4708 NUMBER=PR-2026-000001 BASE=… LOGIN=… PASS=… node workflow.e2e.js
const puppeteer = require('puppeteer-core');
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = process.env.BASE || 'http://pressing-erp.trugroup.cm';
const ORDER = process.env.ORDER, NUMBER = process.env.NUMBER;
let failures = 0;
const check = (label, ok, detail) => { console.log((ok ? 'ok     ' : 'ÉCHEC  ') + label + (ok ? '' : ' — ' + (detail || ''))); if (!ok) failures++; };

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 1000 });
  await page.evaluateOnNewDocument(() => { window.print = () => {}; });
  page.on('dialog', (d) => d.accept());
  const problems = [];
  page.on('pageerror', (e) => problems.push('JS : ' + e.message + ' @ ' + page.url()));
  page.on('response', (r) => { if (r.status() >= 400 && !r.url().endsWith('favicon.ico')) problems.push('HTTP ' + r.status() + ' ' + r.url()); });
  const go = (u) => page.goto(BASE + u, { waitUntil: 'networkidle2' });
  const flash = () => page.$eval('.flash', (e) => e.innerText.trim()).catch(() => '');
  const clickForm = async (valueOfDo) => {
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.evaluate((v) => {
      const f = document.querySelector('form input[name=do][value=' + v + ']').form;
      const rail = f.querySelector('[name=rail]'); if (rail) rail.value = 'R-07';
      const machine = f.querySelector('[name=machine]'); if (machine) machine.value = 'M1 · L-0001';
      f.querySelector('button').click();
    }, valueOfDo)]);
  };

  await go('/login');
  await page.type('#login', process.env.LOGIN);
  await page.type('#password', process.env.PASS);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button.primary')]);

  const codes = [NUMBER + '-01', NUMBER + '-02'];
  for (const code of codes) {
    const trail = [];
    let reachedQuality = false;
    for (let i = 0; i < 16 && !reachedQuality; i++) {
      await go('/scan?code=' + encodeURIComponent(code));
      if (await page.$('a[href^="/qualite/controle/"]')) { reachedQuality = true; break; }
      if (await page.$('form input[name=do][value=start]')) { trail.push('prise en charge'); await clickForm('start'); }
      else if (await page.$('form input[name=do][value=complete]')) { trail.push(await page.$eval('form input[name=do][value=complete]', (e) => e.form.querySelector('button').innerText.trim().slice(0, 40))); await clickForm('complete'); }
      else break;
    }
    console.log('   ' + code + ' :', trail.join(' › '));
    check(code + ' : arrive au contrôle qualité par l\'atelier', reachedQuality, (await flash()) || 'aucune action possible');
    if (!reachedQuality) continue;

    // Contrôle qualité : conforme
    await page.click('a[href^="/qualite/controle/"]');
    await page.waitForSelector('#btn-ok', { timeout: 15000 });
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('#btn-ok')]);
    console.log('   contrôle :', await flash());
    check(code + ' : contrôle conforme enregistré', !(await flash()).toLowerCase().includes('refus'), await flash());

    // Emballage → Prêt
    let ready = false;
    for (let i = 0; i < 4 && !ready; i++) {
      await go('/scan?code=' + encodeURIComponent(code));
      if (await page.$('form input[name=do][value=start]')) { await clickForm('start'); }
      else if (await page.$('form input[name=do][value=complete]')) { await clickForm('complete'); }
      else { ready = true; }
    }
    const state = await page.$eval('body', (e) => e.innerText);
    check(code + ' : pièce prête', ready && /Prêt/.test(state), state.slice(0, 160));
  }

  // Remise au comptoir
  await go('/commandes/' + ORDER);
  const status = await page.$eval('body', (e) => e.innerText);
  check('commande prête (toutes les pièces)', /prête|Prête|Retrait client/.test(status), status.slice(0, 200));
  if (await page.$('form[action="/commandes/' + ORDER + '/retrait"]')) {
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form[action="/commandes/' + ORDER + '/retrait"] button')]);
    console.log('   remise :', await flash());
    check('commande remise au client', (await flash()).toLowerCase().includes('remise'), await flash());
  } else {
    check('formulaire de remise présent', false, 'la commande n\'est pas au statut « prête »');
  }
  await go('/commandes/' + ORDER);
  const final = await page.$eval('body', (e) => e.innerText);
  check('statut final : retirée', /Retirée|retirée/.test(final), final.slice(0, 200));

  console.log(problems.length ? '\nPROBLÈMES SIGNALÉS PAR LE NAVIGATEUR :\n  ' + problems.join('\n  ') : '\naucune erreur JavaScript ni réponse HTTP en erreur');
  console.log(failures ? failures + ' contrôle(s) en échec' : 'ATELIER, QUALITÉ ET REMISE : PARCOURS COMPLET RÉUSSI');
  await browser.close();
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error('ÉCHEC DU SCRIPT', e); process.exit(1); });
