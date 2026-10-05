'use strict';
// Compare offline-core.js au PHP : node tests/js/parity.test.js < référence.json (produite par php tests/js/parity.php)
const core = require('../../public/assets/offline-core.js');
const ref = JSON.parse(require('fs').readFileSync(0, 'utf8'));
let bad = 0, total = 0;
const check = (label, ok, detail) => { total++; if (!ok) { bad++; if (bad <= 15) console.log('ECART', label, detail); } };

ref.dates.forEach(([ts, hours, expected]) => check('date', core.promisedAt(ts, hours) === expected, `${core.format(ts)} +${hours}h : js=${core.promisedAt(ts, hours)} php=${expected}`));
ref.phones.forEach(([raw, norm, valid, fmt]) => {
  const n = core.normalizePhone(raw);
  check('tel normalisé', n === norm, `${JSON.stringify(raw)} js=${n} php=${norm}`);
  check('tel valide', (core.phoneError(n) === null) === valid, `${n} js=${core.phoneError(n) === null} php=${valid}`);
  check('tel format', core.formatPhone(n) === fmt, `${n} js=${core.formatPhone(n)} php=${fmt}`);
});
ref.names.forEach(([name, type, valid]) => check('nom', (core.nameError(name, type) === null) === valid, `${JSON.stringify(name)} ${type} js=${core.nameError(name, type) === null} php=${valid}`));

// Devis : cas calculés à la main
const snap = {
  articles: [{ id: 1, name: 'Chemise', unit: 'piece', fragile: 0 }, { id: 2, name: 'Tapis', unit: 'm2', fragile: 0 }, { id: 3, name: 'Robe de soirée', unit: 'piece', fragile: 1 }, { id: 4, name: 'Costume', unit: 'piece', fragile: 0 }],
  prices: { normal: { 1: 1000, 2: 2500, 3: 6000, 4: 60000 }, vip: { 1: 950 }, business: { 7: { 1: 700 } } },
  levels: [{ value: 'standard', surcharge_pct: 0, delay_hours: 72 }, { value: 'express', surcharge_pct: 50, delay_hours: 24 }],
  settings: { photo_threshold: 50000, nth_pct: 10, delivery_fee: 1500, vat_rate: '19.25' },
};
let q = core.quote(snap, { id: 1, is_vip: 0 }, 'standard', [{ article_id: 1, qty: 3 }, { article_id: 2, qty: '2,5' }], false);
check('devis pièces', q.items.length === 4 && q.subtotal === 3 * 1000 + 6250 && q.total === 9250, JSON.stringify([q.items.length, q.subtotal, q.total]));
q = core.quote(snap, { id: 1, is_vip: 0 }, 'express', [{ article_id: 1, qty: 2 }], true);
check('devis express + livraison', q.surcharge === 1000 && q.delivery_fee === 1500 && q.total === 2000 + 1000 + 1500, JSON.stringify(q));
q = core.quote(snap, { id: 2, is_vip: 1 }, 'standard', [{ article_id: 1, qty: 1 }], false);
check('tarif VIP', q.total === 950, q.total);
q = core.quote(snap, { id: 7, is_vip: 1 }, 'standard', [{ article_id: 1, qty: 1 }], false);
check('contrat avant VIP', q.total === 700, q.total);
q = core.quote(snap, { id: 3, is_vip: 0, next_nth: true }, 'standard', [{ article_id: 1, qty: 10 }], false);
check('fidélité 10 %', q.discount === 1000 && q.total === 9000, JSON.stringify([q.discount, q.total]));
q = core.quote(snap, { id: 3 }, 'standard', [{ article_id: 3, qty: 1 }, { article_id: 4, qty: 1 }, { article_id: 1, qty: 1, damages: 'tache' }], false);
check('photos exigées', q.photo_lines[0] === 'article fragile' && /valeur/.test(q.photo_lines[1]) && q.photo_lines[2] === 'déjà endommagé', JSON.stringify(q.photo_lines));
let threw = false; try { core.quote({ ...snap, prices: { normal: {}, vip: {}, business: {} } }, null, 'standard', [{ article_id: 1, qty: 1 }], false); } catch (e) { threw = /Aucun tarif/.test(e.message); }
check('tarif manquant', threw, '');
check('TVA incluse', core.vatIncluded(11925, '19.25') === 1925 && core.vatIncluded(11925, '0') === 0, core.vatIncluded(11925, '19.25'));
check('numéro', core.orderNumber(2026, 124) === 'PR-2026-000124', core.orderNumber(2026, 124));
check('jeton', /^[0-9a-f]{32}$/.test(core.randomToken()) && core.randomToken() !== core.randomToken(), '');
const html = core.ticketHtml({ number: 'PR-2026-000124', created_at: '2026-10-05 14:30:00', promised_at: '2026-10-08 15:00:00', client: { name: '<b>Jean</b> Mbarga', code: 'CL-1', phone: '+237670123456' }, level: { label: 'Standard' }, items: [{ label: 'Chemise', price: 1000 }], subtotal: 1000, surcharge: 0, discount: 0, delivery_fee: 0, total: 1000, tracking_token: 'a'.repeat(32) }, { settings: { company: 'Pressing', niu: '', vat_rate: '19.25', app_url: 'http://x' }, agency: { name: 'Akwa' }, user: { name: 'Fatou' } });
check('ticket échappe le HTML et mentionne le hors-ligne', !html.includes('<b>Jean</b>') && html.includes('COPIE HORS-LIGNE') && html.includes('PR-2026-000124'), '');

console.log(`${total - bad} / ${total} vérifications identiques au PHP`);
process.exit(bad ? 1 : 0);
