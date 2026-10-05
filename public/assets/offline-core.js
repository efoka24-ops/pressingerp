/*
 * Réception hors-ligne : règles métier côté navigateur.
 * Miroir volontaire du code PHP (ClientService, ServiceLevel::promisedAt, PricingService::quote) : la synchronisation
 * recalcule tout côté serveur et signale tout écart. Ce fichier est sans accès au DOM ni au réseau pour rester testable.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) { module.exports = factory(); } else { root.OfflineCore = factory(); }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var TZ_SECONDS = 3600; // Africa/Douala = UTC+1, sans heure d'été

  // --- Identification du client (miroir de ClientService) -----------------------------------------
  function normalizePhone(raw) {
    var p = String(raw == null ? '' : raw).replace(/[^\d+]/g, '');
    if (p.indexOf('00') === 0) { p = '+' + p.slice(2); }
    if (p.charAt(0) !== '+' && p.length === 9 && (p.charAt(0) === '6' || p.charAt(0) === '2')) { p = '+237' + p; }
    return p;
  }

  function phoneError(p) {
    if (/^\+237[62]\d{8}$/.test(p)) { return null; }
    if (/^\+(?!237)[1-9]\d{7,14}$/.test(p)) { return null; }
    return 'Numéro invalide : 9 chiffres commençant par 6 (mobile) ou 2 (fixe), par exemple 6 70 12 34 56. Un numéro étranger s\'écrit avec son indicatif (+33…).';
  }

  function nameError(name, type) {
    name = String(name == null ? '' : name).replace(/\s+/g, ' ').trim();
    if (type === 'pro') { return name.length >= 3 ? null : 'Raison sociale trop courte.'; }
    var parts = name.split(' ');
    var shortest = Math.min.apply(null, parts.map(function (x) { return x.length; }));
    if (parts.length < 2 || shortest < 2) { return 'Nom et prénom obligatoires : le client doit pouvoir être identifié.'; }
    return null;
  }

  function formatPhone(p) {
    var m = /^\+237(\d)(\d{2})(\d{2})(\d{2})(\d{2})$/.exec(p);
    return m ? '+237 ' + m.slice(1).join(' ') : p;
  }

  // --- Dates en heure de Douala (miroir de ServiceLevel::promisedAt) -------------------------------
  function pad(n) { return (n < 10 ? '0' : '') + n; }

  /** Découpe un instant (secondes Unix) en champs d'heure locale. */
  function parts(ts) {
    var d = new Date((ts + TZ_SECONDS) * 1000);
    return { Y: d.getUTCFullYear(), M: d.getUTCMonth() + 1, D: d.getUTCDate(), h: d.getUTCHours(), m: d.getUTCMinutes(), s: d.getUTCSeconds(), dow: d.getUTCDay() === 0 ? 7 : d.getUTCDay() };
  }

  function format(ts) {
    var p = parts(ts);
    return p.Y + '-' + pad(p.M) + '-' + pad(p.D) + ' ' + pad(p.h) + ':' + pad(p.m) + ':' + pad(p.s);
  }

  function localMidnight(ts) { return Math.floor((ts + TZ_SECONDS) / 86400) * 86400 - TZ_SECONDS; }

  /** Date promise : délai, arrondi à l'heure, ramené dans les heures d'ouverture (8 h–19 h), fermé le dimanche. */
  function promisedAt(fromTs, delayHours) {
    var t = fromTs + delayHours * 3600;
    t = Math.ceil(t / 3600) * 3600;
    var h = parts(t).h;
    if (h < 8) { t = localMidnight(t) + 10 * 3600; }
    else if (h >= 19) { t = localMidnight(t + 86400) + 10 * 3600; }
    if (parts(t).dow === 7) { t += 86400; }
    return format(t);
  }

  /** « 2026-10-05 14:30:00 » (heure de Douala) -> secondes Unix. */
  function parse(str) {
    var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(str);
    return m ? Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]) / 1000 - TZ_SECONDS : NaN;
  }

  function dmyHm(str) {
    var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(str || '');
    return m ? m[3] + '/' + m[2] + ' ' + m[4] + ':' + m[5] : '';
  }

  // --- Devis (miroir de PricingService::quote) ------------------------------------------------------
  function unitPrice(snap, client, articleId) {
    var p = snap.prices;
    var biz = client && p.business && p.business[client.id];
    if (biz && biz[articleId] != null) { return biz[articleId]; }
    if (client && client.is_vip && p.vip && p.vip[articleId] != null) { return p.vip[articleId]; }
    return p.normal && p.normal[articleId] != null ? p.normal[articleId] : null;
  }

  function photoReason(item, threshold) {
    if (item.fragile) { return 'article fragile'; }
    if (item.damages) { return 'déjà endommagé'; }
    if (threshold > 0 && item.price >= threshold) { return 'pièce de valeur (≥ ' + threshold + ' FCFA)'; }
    return null;
  }

  /**
   * lines : [{article_id, qty, treatment_id, brand, color, material, damages}] -> pièces et totaux.
   * Jette une Error('Aucun tarif pour …') si un article n'a pas de prix.
   */
  function quote(snap, client, levelValue, lines, delivery) {
    var level = snap.levels.filter(function (l) { return l.value === levelValue; })[0] || snap.levels[0];
    var items = [];
    var subtotal = 0;
    var photoLines = {};
    lines.forEach(function (l, index) {
      var a = snap.articles.filter(function (x) { return x.id === Number(l.article_id); })[0];
      if (!a) { throw new Error('Article inconnu.'); }
      var price = unitPrice(snap, client, a.id);
      if (price == null) { throw new Error('Aucun tarif pour « ' + a.name + ' ». Le responsable doit le renseigner.'); }
      var qty = parseFloat(String(l.qty == null ? 1 : l.qty).replace(',', '.'));
      if (!isFinite(qty)) { qty = 1; }
      var base = { line: index, article_id: a.id, label: a.name, fragile: !!a.fragile, brand: (l.brand || '').trim(), color: (l.color || '').trim(), material: (l.material || '').trim(), damages: (l.damages || '').trim(), treatment_id: l.treatment_id ? Number(l.treatment_id) : null };
      if (a.unit === 'm2') {
        qty = Math.max(0.1, Math.round(qty * 100) / 100);
        var line = Math.round(price * qty);
        items.push(Object.assign({}, base, { qty: qty, price: line }));
        subtotal += line;
      } else {
        var n = Math.max(1, Math.min(100, Math.trunc(qty)));
        for (var i = 0; i < n; i++) { items.push(Object.assign({}, base, { qty: 1, price: price })); subtotal += price; }
      }
    });
    items.forEach(function (it) {
      it.photo_reason = photoReason(it, snap.settings.photo_threshold);
      if (it.photo_reason) { photoLines[it.line] = it.photo_reason; }
    });
    var surcharge = Math.round(subtotal * level.surcharge_pct / 100);
    var discount = 0;
    var label = null;
    if (client && client.next_nth && subtotal + surcharge > 0) {
      var pct = snap.settings.nth_pct;
      discount = Math.round((subtotal + surcharge) * pct / 100);
      label = 'Fidélité (−' + pct + ' %)';
    }
    var fee = delivery ? snap.settings.delivery_fee : 0;
    return { items: items, subtotal: subtotal, surcharge: surcharge, discount: discount, discount_label: label, delivery_fee: fee, total: Math.max(0, subtotal + surcharge - discount + fee), photo_lines: photoLines, level: level };
  }

  function vatIncluded(total, rate) {
    rate = parseFloat(String(rate).replace(',', '.'));
    return rate > 0 ? Math.round(total - total / (1 + rate / 100)) : 0;
  }

  // --- Numéros ------------------------------------------------------------------------------------
  function orderNumber(year, seq) { return 'PR-' + year + '-' + String(seq).padStart(6, '0'); }

  function randomToken() {
    var b = new Uint8Array(16);
    (typeof crypto !== 'undefined' ? crypto : require('crypto').webcrypto).getRandomValues(b);
    return Array.prototype.map.call(b, function (x) { return (x < 16 ? '0' : '') + x.toString(16); }).join('');
  }

  // --- Documents imprimables (miroirs des vues PHP orders/ticket et orders/labels) -------------------
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) { return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }

  function trackingUrl(snap, token) { return snap.settings.app_url + '/suivi/' + token; }

  function ticketHtml(o, snap) {
    var s = snap.settings;
    var groups = {};
    o.items.forEach(function (it) { var k = it.label + '|' + it.price; groups[k] = groups[k] || { label: it.label, price: it.price, qty: 0 }; groups[k].qty++; });
    var rows = Object.keys(groups).map(function (k) { var g = groups[k]; return '<tr><td>' + g.qty + ' × ' + esc(g.label) + '</td><td class="r">' + money(g.qty * g.price) + '</td></tr>'; }).join('');
    var url = trackingUrl(snap, o.tracking_token);
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Ticket ' + esc(o.number) + '</title>' +
      '<script src="/assets/vendor/qrcode.min.js"><\/script>' +
      '<style>@page{size:80mm auto;margin:0}body{width:72mm;margin:4mm;font:11px/1.35 \'Courier New\',monospace;color:#000}h1{font-size:14px;text-align:center;margin:0}.c{text-align:center}.r{text-align:right}hr{border:0;border-top:1px dashed #000;margin:6px 0}table{width:100%;border-collapse:collapse}td{vertical-align:top;padding:1px 0}.tot{font-size:14px;font-weight:700}#qr{display:flex;justify-content:center;margin:6px 0}.off{border:2px solid #000;padding:3px;text-align:center;font-weight:700;margin:4px 0}</style></head><body>' +
      '<h1>' + esc(s.company) + '</h1><div class="c">' + esc(snap.agency.name) + (s.niu ? '<br>NIU : ' + esc(s.niu) : '') + '</div>' +
      '<div class="off">COPIE HORS-LIGNE — en attente de synchronisation</div><hr>' +
      '<div>Commande <b>' + esc(o.number) + '</b></div><div>Déposé le ' + esc(dmyHm(o.created_at)) + ' par ' + esc(snap.user.name) + '</div>' +
      '<div>Client : <b>' + esc(o.client.name) + '</b>' + (o.client.code ? ' (' + esc(o.client.code) + ')' : '') + '</div><div>Tél : ' + esc(formatPhone(o.client.phone)) + '</div>' +
      '<div>Service ' + esc(o.level.label) + ' · à retirer le <b>' + esc(dmyHm(o.promised_at)) + '</b></div><hr>' +
      '<table>' + rows + '<tr><td>Sous-total</td><td class="r">' + money(o.subtotal) + '</td></tr>' +
      (o.surcharge ? '<tr><td>Majoration</td><td class="r">' + money(o.surcharge) + '</td></tr>' : '') +
      (o.discount ? '<tr><td>' + esc(o.discount_label || 'Remise') + '</td><td class="r">−' + money(o.discount) + '</td></tr>' : '') +
      (o.delivery_fee ? '<tr><td>Livraison</td><td class="r">' + money(o.delivery_fee) + '</td></tr>' : '') + '</table><hr>' +
      '<table><tr class="tot"><td>TOTAL TTC</td><td class="r">' + money(o.total) + ' FCFA</td></tr>' +
      '<tr><td>dont TVA ' + esc(String(s.vat_rate).replace(/0+$/, '').replace(/\.$/, '')) + ' %</td><td class="r">' + money(vatIncluded(o.total, s.vat_rate)) + '</td></tr>' +
      '<tr class="tot"><td>RESTE À PAYER</td><td class="r">' + money(o.total) + '</td></tr></table><hr>' +
      '<div>' + o.items.length + ' pièce' + (o.items.length > 1 ? 's' : '') + ' (n° d\'étiquette ' + o.items.map(function (it, i) { return pad(i + 1); }).join(', ') + ')</div>' +
      '<div id="qr"></div><div class="c">Suivi en ligne : scannez ou allez sur<br>' + esc(url) + '</div><hr>' +
      '<div class="c">Paiement à effectuer au retrait ou dès la synchronisation. Conservez ce ticket : il est exigé au retrait.</div>' +
      '<script>new QRCode(document.getElementById("qr"),{text:' + JSON.stringify(url) + ',width:110,height:110,correctLevel:QRCode.CorrectLevel.M});window.addEventListener("load",function(){setTimeout(function(){(window.opener&&window.opener.__noPrint)||window.print()},400)})<\/script></body></html>';
  }

  function labelsHtml(o, snap) {
    var cells = o.items.map(function (it, i) {
      var code = o.number + '-' + pad(i + 1);
      return '<div class="l"><div class="qr" data-code="' + esc(code) + '"></div><div class="t"><span class="c">' + esc(code) + '</span><span class="s">' + esc(it.label) + '</span>' +
        '<span class="m">' + esc(o.client.name) + '</span><span class="m">' + esc([it.color, it.material].filter(Boolean).join(' · ')) + '</span>' +
        '<span>' + esc(o.level.label) + ' · ' + esc(dmyHm(o.promised_at)) + '</span>' +
        '<span class="m">' + (i + 1) + ' / ' + o.items.length + (it.damages ? ' · ⚠ ' + esc(it.damages.slice(0, 30)) : '') + '</span></div></div>';
    }).join('');
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Étiquettes ' + esc(o.number) + '</title><script src="/assets/vendor/qrcode.min.js"><\/script>' +
      '<style>@page{size:62mm 40mm;margin:0}*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;color:#000}.l{width:62mm;height:40mm;padding:3mm;display:flex;gap:3mm;page-break-after:always;overflow:hidden}.qr{width:26mm;height:26mm;flex:none}.qr img,.qr canvas{width:26mm!important;height:26mm!important}.t{display:flex;flex-direction:column;gap:1mm;font-size:8pt;line-height:1.2;min-width:0}.c{font:700 9pt \'Courier New\',monospace}.s{font-weight:700;font-size:9pt}.m{color:#333}@media screen{body{background:#eee;padding:10px;display:flex;flex-wrap:wrap;gap:10px}.l{background:#fff}}</style></head><body>' + cells +
      '<script>document.querySelectorAll(".qr").forEach(function(el){new QRCode(el,{text:el.dataset.code,width:200,height:200,correctLevel:QRCode.CorrectLevel.M})});window.addEventListener("load",function(){setTimeout(function(){(window.opener&&window.opener.__noPrint)||window.print()},400)})<\/script></body></html>';
  }

  return {
    normalizePhone: normalizePhone, phoneError: phoneError, nameError: nameError, formatPhone: formatPhone,
    promisedAt: promisedAt, format: format, parse: parse, dmyHm: dmyHm,
    quote: quote, unitPrice: unitPrice, photoReason: photoReason, vatIncluded: vatIncluded,
    orderNumber: orderNumber, randomToken: randomToken, ticketHtml: ticketHtml, labelsHtml: labelsHtml, esc: esc, money: money
  };
});
