/*
 * Réception hors-ligne : base locale (IndexedDB), saisie, impression et synchronisation.
 * Les règles métier sont dans offline-core.js (testées contre le PHP). Voir app/Services/OfflineService.php.
 */
(function () {
  'use strict';
  var C = window.OfflineCore;
  var $ = function (id) { return document.getElementById(id); };
  var TOKEN_KEY = 'pressing.station';

  // ---------------------------------------------------------------- Base locale
  var dbp = null;
  function db() {
    if (!dbp) {
      dbp = new Promise(function (resolve, reject) {
        var r = indexedDB.open('pressing-offline', 1);
        r.onupgradeneeded = function () {
          r.result.createObjectStore('meta', { keyPath: 'key' });
          r.result.createObjectStore('orders', { keyPath: 'number' });
        };
        r.onsuccess = function () { resolve(r.result); };
        r.onerror = function () { reject(r.error); };
      });
    }
    return dbp;
  }
  function req(r) { return new Promise(function (res, rej) { r.onsuccess = function () { res(r.result); }; r.onerror = function () { rej(r.error); }; }); }
  function done(tx) { return new Promise(function (res, rej) { tx.oncomplete = function () { res(); }; tx.onerror = tx.onabort = function () { rej(tx.error || new Error('transaction annulée')); }; }); }
  async function metaGet(key) { var d = await db(); var row = await req(d.transaction('meta').objectStore('meta').get(key)); return row ? row.value : null; }
  async function metaSet(key, value) { var d = await db(); var tx = d.transaction('meta', 'readwrite'); tx.objectStore('meta').put({ key: key, value: value }); await done(tx); }
  async function ordersAll() { var d = await db(); var rows = await req(d.transaction('orders').objectStore('orders').getAll()); return rows.sort(function (a, b) { return a.number < b.number ? 1 : -1; }); }
  async function orderPut(o) { var d = await db(); var tx = d.transaction('orders', 'readwrite'); tx.objectStore('orders').put(o); await done(tx); }
  async function orderGet(n) { var d = await db(); return req(d.transaction('orders').objectStore('orders').get(n)); }

  // ---------------------------------------------------------------- État
  var snap = null;        // données du poste (articles, tarifs, clients…)
  var blocks = [];        // plages de numéros : [{year, from, to, next}]
  var offset = 0;         // décalage entre l'horloge du serveur et celle de l'appareil (secondes)
  var client = null;      // client choisi
  var rows = [];          // lignes de la commande en cours : {article, photo(Blob|null)}
  var levelValue = 'standard';
  var syncing = false;

  function token() { try { return localStorage.getItem(TOKEN_KEY) || ''; } catch (e) { return ''; } }
  function nowTs() { return Math.floor(Date.now() / 1000 + offset); }
  function online() { return navigator.onLine !== false; }
  function say(text, kind) {
    var el = document.createElement('div');
    el.className = 'flash ' + (kind || 'ok'); el.textContent = text;
    $('msg').innerHTML = ''; $('msg').appendChild(el);
    if (kind !== 'err') { setTimeout(function () { if (el.parentNode) el.remove(); }, 8000); }
  }
  function remaining() {
    return blocks.reduce(function (n, b) { return n + Math.max(0, b.to - b.next + 1); }, 0);
  }

  // ---------------------------------------------------------------- Réseau
  async function csrf() {
    var r = await fetch('/api/hors-ligne/csrf', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
    var ct = r.headers.get('content-type') || '';
    if (r.redirected || ct.indexOf('json') < 0) { var e = new Error('Session expirée : reconnectez-vous pour synchroniser.'); e.session = true; throw e; }
    return (await r.json()).token;
  }
  async function api(path, opts) {
    opts = opts || {};
    var t = await csrf();
    var headers = Object.assign({ 'X-CSRF-Token': t, 'X-Station': token(), 'Accept': 'application/json' }, opts.headers || {});
    var r = await fetch(path, { method: 'POST', credentials: 'same-origin', headers: headers, body: opts.body });
    var ct = r.headers.get('content-type') || '';
    if (r.redirected || ct.indexOf('json') < 0) { var e = new Error('Session expirée : reconnectez-vous pour synchroniser.'); e.session = true; throw e; }
    var j = await r.json();
    j._http = r.status;
    return j;
  }

  // ---------------------------------------------------------------- Chargement et rafraîchissement
  async function loadState() {
    snap = await metaGet('snapshot');
    blocks = (await metaGet('blocks')) || [];
    offset = (await metaGet('offset')) || 0;
  }

  async function refreshData(silent) {
    if (!token()) { return; }
    if (!online()) { if (!silent) { say('Pas de réseau : les données locales restent utilisables.', 'warn'); } return; }
    try {
      await syncAll(true);
      var j = await api('/api/hors-ligne/donnees', { body: '{}', headers: { 'Content-Type': 'application/json' } });
      if (!j.ok) { say(j.error || 'Actualisation refusée.', 'err'); return; }
      var nb = blocks.slice();
      if (j.block) { nb.push({ year: j.block.year, from: j.block.from, to: j.block.to, next: j.block.from }); }
      delete j.ok; delete j.block; delete j.unused; delete j._http;
      await metaSet('snapshot', j);
      await metaSet('blocks', nb.filter(function (b) { return b.next <= b.to; }));
      await metaSet('offset', j.server_time - Math.floor(Date.now() / 1000));
      await loadState();
      render();
      if (!silent) { say('Données actualisées : ' + snap.clients.length + ' clients, ' + snap.articles.length + ' articles, ' + remaining() + ' numéros disponibles.'); }
    } catch (e) {
      if (!silent || e.session) { say(e.message || 'Actualisation impossible.', e.session ? 'err' : 'warn'); }
    }
  }

  async function register() {
    var label = $('station-label').value.trim();
    if (!label) { say('Donnez un nom au poste.', 'err'); return; }
    if (!online()) { say('Le réseau est nécessaire pour autoriser un poste.', 'err'); return; }
    try {
      var j = await api('/api/hors-ligne/poste', { body: 'label=' + encodeURIComponent(label), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
      if (!j.ok) { say(j.error || 'Autorisation refusée (réservée aux responsables).', 'err'); return; }
      try { localStorage.setItem(TOKEN_KEY, j.token); } catch (e) { say('Ce navigateur interdit le stockage local : mode hors-ligne impossible.', 'err'); return; }
      say('Poste autorisé.');
      await refreshData(false);
      init();
    } catch (e) { say(e.message, 'err'); }
  }

  // ---------------------------------------------------------------- Synchronisation
  async function syncAll(quiet) {
    if (syncing || !token() || !online()) { return; }
    syncing = true;
    var ok = 0, rejected = 0;
    try {
      var pending = (await ordersAll()).filter(function (o) { return o.status === 'pending'; }).reverse();
      for (var i = 0; i < pending.length; i++) {
        var o = pending[i];
        var fd = new FormData();
        var payload = {
          number: o.number, created_at: o.created_at, snapshot_at: o.snapshot_at, user_id: o.user_id, client: o.client_ref, service_level: o.level.value,
          lines: o.lines, delivery: o.delivery, delivery_address: o.delivery_address, notes: o.notes, total: o.total, tracking_token: o.tracking_token, labels_printed: !!o.labels_printed
        };
        fd.append('payload', JSON.stringify(payload));
        Object.keys(o.photos || {}).forEach(function (k) { fd.append('photos[' + k + ']', o.photos[k], 'photo-' + k + '.jpg'); });
        var j = await api('/api/hors-ligne/commandes', { body: fd });
        if (j.status === 'created' || j.status === 'duplicate') {
          o.status = 'synced'; o.order_id = j.order_id; o.synced_at = new Date().toISOString(); o.photos = {};
          ok++;
        } else {
          o.status = 'rejected'; o.message = j.message || j.error || 'Rejetée par le serveur'; rejected++;
        }
        await orderPut(o);
      }
      if (!quiet) { say(pending.length ? ok + ' commande(s) synchronisée(s)' + (rejected ? ', ' + rejected + ' rejetée(s) : à ressaisir en ligne' : '') + '.' : 'Rien à synchroniser.', rejected ? 'warn' : 'ok'); }
    } catch (e) {
      say(e.message || 'Synchronisation interrompue : nouvel essai dès que le réseau revient.', e.session ? 'err' : 'warn');
    } finally { syncing = false; }
    await renderQueue();
  }

  // ---------------------------------------------------------------- Nouvelle commande
  function buildQuote() {
    if (!snap || !rows.length) { return null; }
    try {
      var lines = rows.map(rowToLine);
      return C.quote(snap, client, levelValue, lines, $('delivery').checked);
    } catch (e) { return { error: e.message }; }
  }
  function rowToLine(r) {
    var tr = r.tr;
    return {
      article_id: r.article.id, qty: tr.querySelector('.f-qty').value, treatment_id: tr.querySelector('.f-treat').value,
      brand: tr.querySelector('.f-brand').value, color: tr.querySelector('.f-color').value, material: tr.querySelector('.f-material').value, damages: tr.querySelector('.f-dmg').value
    };
  }

  function addRow(article) {
    var tr = document.createElement('tr');
    var m2 = article.unit === 'm2';
    var treat = snap.treatments.map(function (t) { return '<option value="' + t.id + '">' + C.esc(t.label) + '</option>'; }).join('');
    tr.innerHTML = '<td class="mono muted seq"></td>' +
      '<td class="nowrap"><b>' + C.esc(article.name) + '</b>' + (article.fragile ? ' <span class="badge orange">fragile</span>' : '') + '</td>' +
      '<td><input class="input mono f-qty" style="width:76px" type="number" value="1" min="' + (m2 ? '0.1' : '1') + '" step="' + (m2 ? '0.1' : '1') + '"></td>' +
      '<td><select class="input f-treat" style="width:150px">' + treat + '</select></td>' +
      '<td><input class="input f-brand" style="width:110px"></td><td><input class="input f-color" style="width:100px"></td><td><input class="input f-material" style="width:110px"></td>' +
      '<td><input class="input f-dmg" style="width:170px" placeholder="ex. tache vin devant"></td>' +
      '<td><input type="file" class="f-photo" accept="image/*" capture="environment" style="width:170px;font-size:12px"></td>' +
      '<td><button type="button" class="btn sm" title="Retirer">✕</button></td>';
    var row = { article: article, tr: tr, photo: null };
    rows.push(row);
    tr.querySelectorAll('input,select').forEach(function (el) { el.addEventListener('input', recompute); el.addEventListener('change', recompute); });
    tr.querySelector('.f-photo').addEventListener('change', function () { shrink(this, row); });
    tr.querySelector('button').onclick = function () { rows.splice(rows.indexOf(row), 1); tr.remove(); recompute(); };
    $('lines').querySelector('tbody').appendChild(tr);
    recompute();
  }

  // Photos réduites avant stockage (espace local et envoi)
  function shrink(input, row) {
    var f = input.files[0];
    row.photo = f || null;
    if (!f || f.size < 900000) { recompute(); return; }
    var img = new Image();
    img.onload = function () {
      var s = Math.min(1, 1600 / Math.max(img.width, img.height)), c = document.createElement('canvas');
      c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      c.toBlob(function (b) { if (b) { row.photo = b; } recompute(); }, 'image/jpeg', 0.8);
    };
    img.onerror = recompute;
    img.src = URL.createObjectURL(f);
  }

  function recompute() {
    var q = buildQuote();
    var tbody = $('lines').querySelector('tbody');
    $('lines-empty').hidden = rows.length > 0;
    var missing = 0;
    rows.forEach(function (r, i) {
      r.tr.querySelector('.seq').textContent = '-' + String(i + 1).padStart(2, '0');
      var need = q && !q.error && q.photo_lines && Object.keys(q.photo_lines).indexOf(String(i)) >= 0;
      var lacks = need && !r.photo;
      r.tr.className = lacks ? 'warn' : '';
      if (lacks) { missing++; }
    });
    var ok = q && !q.error;
    $('q-count').textContent = ok ? q.items.length + ' pièce' + (q.items.length > 1 ? 's' : '') + ' · sous-total' : '0 pièce';
    $('q-subtotal').textContent = ok ? C.money(q.subtotal) : '0';
    $('q-surcharge-row').hidden = !ok || !q.surcharge; if (ok) { $('q-surcharge').textContent = C.money(q.surcharge); }
    $('q-discount-row').hidden = !ok || !q.discount; if (ok && q.discount) { $('q-discount-label').textContent = q.discount_label; $('q-discount').textContent = '−' + C.money(q.discount); }
    $('q-delivery-row').hidden = !ok || !q.delivery_fee; if (ok) { $('q-delivery').textContent = C.money(q.delivery_fee); }
    $('q-total').innerHTML = (ok ? C.money(q.total) : '0') + ' <small>FCFA</small>';
    var lvl = snap.levels.filter(function (l) { return l.value === levelValue; })[0];
    $('q-promised').textContent = C.dmyHm(C.promisedAt(nowTs(), lvl.delay_hours));
    var reason = '';
    if (!client) { reason = 'Sélectionnez un client.'; }
    else if (!rows.length) { reason = 'Ajoutez au moins un article.'; }
    else if (q && q.error) { reason = q.error; }
    else if (missing) { reason = 'Bloqué : ' + missing + ' photo(s) obligatoire(s) manquante(s) (article fragile, endommagé ou de valeur).'; }
    else if ($('delivery').checked && !$('delivery-address').value.trim()) { reason = 'Adresse de livraison obligatoire.'; }
    else if (remaining() < 1) { reason = 'Plage de numéros épuisée : reconnectez-vous au réseau pour en obtenir une nouvelle.'; }
    $('submit').disabled = !!reason;
    $('blocker').textContent = reason || 'Le client recevra un SMS avec son lien de suivi dès la synchronisation.';
    $('numbers').textContent = remaining() + ' numéro(s) de commande disponible(s) sur ce poste';
  }

  async function submit() {
    var q = buildQuote();
    if (!q || q.error || !client) { return; }
    $('submit').disabled = true;
    var lines = rows.map(rowToLine);
    var photos = {};
    rows.forEach(function (r, i) { if (r.photo) { photos[i] = r.photo; } });
    var createdTs = nowTs();
    var clientRef = client.id ? { id: client.id } : { name: client.name, phone: client.phone, type: client.type };
    var order = {
      number: null, status: 'pending', created_at: C.format(createdTs), snapshot_at: snap.snapshot_at, promised_at: C.promisedAt(createdTs, q.level.delay_hours),
      client: { id: client.id || null, name: client.name, phone: client.phone, code: client.code || '' }, client_ref: clientRef, user_id: snap.user.id,
      level: { value: q.level.value, label: q.level.label }, items: q.items, lines: lines, subtotal: q.subtotal, surcharge: q.surcharge, discount: q.discount,
      discount_label: q.discount_label, delivery_fee: q.delivery_fee, total: q.total, delivery: $('delivery').checked, delivery_address: $('delivery-address').value.trim(),
      notes: $('notes').value.trim(), tracking_token: C.randomToken(), photos: photos, labels_printed: false,
      print_ctx: { settings: snap.settings, agency: snap.agency, user: snap.user }
    };
    try {
      // Numéro attribué et commande enregistrée dans la même transaction : jamais de numéro perdu ni dupliqué
      var d = await db();
      var tx = d.transaction(['meta', 'orders'], 'readwrite');
      var bl = (await req(tx.objectStore('meta').get('blocks'))).value;
      var b = bl.filter(function (x) { return x.next <= x.to; })[0];
      if (!b) { tx.abort(); throw new Error('Plage de numéros épuisée.'); }
      order.number = C.orderNumber(b.year, b.next);
      b.next += 1;
      tx.objectStore('meta').put({ key: 'blocks', value: bl.filter(function (x) { return x.next <= x.to; }) });
      tx.objectStore('orders').put(order);
      await done(tx);
    } catch (e) { say(e.message || 'Enregistrement local impossible.', 'err'); recompute(); return; }
    await loadState();
    printTicket(order);
    resetForm();
    say('Commande ' + order.number + ' enregistrée sur ce poste. Imprimez les étiquettes ci-dessous ; elle sera envoyée au serveur dès que le réseau est disponible.');
    await renderQueue();
    if (online()) { syncAll(true); }
  }

  function resetForm() {
    $('client-search').value = ''; $('client-results').innerHTML = '';
    rows = []; $('lines').querySelector('tbody').innerHTML = ''; client = null; $('notes').value = ''; $('delivery').checked = false; $('delivery-box').hidden = true; $('delivery-address').value = '';
    renderClient(); recompute();
    try { localStorage.removeItem('offline-draft'); } catch (e) {}
  }

  // ---------------------------------------------------------------- Impression
  function popup(html) {
    var w = window.open('', '_blank');
    if (!w) { say('Le navigateur a bloqué la fenêtre d\'impression : autorisez les fenêtres pour ce site.', 'warn'); return; }
    w.document.open(); w.document.write(html); w.document.close();
  }
  function ctxSnap(o) { return { settings: o.print_ctx.settings, agency: o.print_ctx.agency, user: o.print_ctx.user }; }
  function printTicket(o) { popup(C.ticketHtml(o, ctxSnap(o))); }
  async function printLabels(number) {
    var o = await orderGet(number);
    popup(C.labelsHtml(o, ctxSnap(o)));
    if (!o.labels_printed && o.status === 'pending') { o.labels_printed = true; await orderPut(o); }
  }

  // ---------------------------------------------------------------- Affichage
  function renderClient() {
    $('client-picked').hidden = !client; $('client-search-box').hidden = !!client;
    if (client) {
      $('cp-name').textContent = client.name;
      $('cp-meta').textContent = (client.code ? client.code + ' · ' : '') + C.formatPhone(client.phone) + (client.id ? '' : ' · nouveau client');
      $('cp-pref').textContent = client.preferences || '';
    }
  }
  function searchClients() {
    var q = $('client-search').value.trim().toLowerCase(), digits = q.replace(/\D/g, '');
    var box = $('client-results'); box.innerHTML = '';
    if (q.length < 2) { return; }
    var hits = snap.clients.filter(function (c) { return c.name.toLowerCase().indexOf(q) >= 0 || c.code.toLowerCase().indexOf(q) >= 0 || (digits.length >= 3 && c.phone.replace(/\D/g, '').indexOf(digits) >= 0); }).slice(0, 8);
    if (!hits.length) { box.innerHTML = '<span class="small muted">Aucun client dans les données du poste. Créez-le ci-dessous (nom complet et téléphone).</span>'; return; }
    hits.forEach(function (c) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'tile'; b.style.minHeight = '48px';
      b.disabled = !!c.blocked;
      b.innerHTML = '<b>' + C.esc(c.name) + (c.is_vip ? ' <span class="tag vip">VIP</span>' : '') + (c.type === 'pro' ? ' <span class="tag pro">PRO</span>' : '') + '</b><span>' + C.esc(c.code + ' · ' + c.phone_fmt) + (c.blocked ? ' · ' + C.esc(c.blocked) : '') + '</span>';
      b.onclick = function () { client = c; renderClient(); recompute(); };
      box.appendChild(b);
    });
  }
  function addNewClient() {
    var name = $('nc-name').value, type = $('nc-type').value, phone = C.normalizePhone($('nc-phone').value);
    var err = C.nameError(name, type) || C.phoneError(phone);
    if (err) { say(err, 'err'); return; }
    var dup = snap.clients.filter(function (c) { return c.phone === phone; })[0];
    if (dup) { say('Ce numéro appartient déjà à ' + dup.name + ' : sélectionnez-le dans la recherche.', 'err'); return; }
    client = { id: null, name: name.replace(/\s+/g, ' ').trim(), phone: phone, type: type, is_vip: 0, code: '', next_nth: false };
    $('nc-name').value = ''; $('nc-phone').value = '';
    renderClient(); recompute();
  }

  async function renderQueue() {
    var all = await ordersAll();
    var pending = all.filter(function (o) { return o.status === 'pending'; }).length;
    $('pending-count').textContent = pending ? '(' + pending + ')' : '';
    $('queue-hint').textContent = pending ? pending + ' en attente de synchronisation' : 'Tout est synchronisé';
    var html = all.map(function (o) {
      var st = o.status === 'pending' ? '<span class="st-pending">En attente</span>' : o.status === 'synced' ? '<span class="st-synced">Synchronisée</span>' : '<span class="st-rejected">Rejetée : ' + C.esc(o.message || '') + '</span>';
      var acts = '<button class="btn sm" data-act="ticket" data-n="' + o.number + '">Ticket</button> <button class="btn sm" data-act="labels" data-n="' + o.number + '">Étiquettes</button>' +
        (o.order_id ? ' <a class="btn sm" href="/commandes/' + o.order_id + '">Ouvrir</a>' : '');
      return '<tr><td class="mono strong">' + C.esc(o.number) + '</td><td>' + C.esc(o.client.name) + '</td><td class="num mono">' + C.money(o.total) + '</td><td class="mono small">' + C.esc(C.dmyHm(o.created_at)) + '</td><td>' + st + '</td><td>' + acts + '</td></tr>';
    }).join('');
    $('queue').innerHTML = html || '<tr><td colspan="6" class="muted">Aucune commande saisie sur ce poste.</td></tr>';
    // Données locales des commandes synchronisées depuis plus de 14 jours : purgées
    var limit = Date.now() - 14 * 86400000, d = await db();
    all.filter(function (o) { return o.status === 'synced' && Date.parse(o.synced_at || 0) < limit; }).forEach(function (o) { d.transaction('orders', 'readwrite').objectStore('orders').delete(o.number); });
  }

  function render() {
    if (!snap) { return; }
    $('who').textContent = snap.agency.name + ' · ' + snap.user.name + ' · données du ' + C.dmyHm(snap.snapshot_at);
    $('tiles').innerHTML = '';
    snap.articles.forEach(function (a) {
      var p = C.unitPrice(snap, client, a.id);
      var b = document.createElement('button'); b.type = 'button'; b.className = 'tile';
      b.innerHTML = '<b>' + C.esc(a.name) + '</b><span>' + (a.unit === 'm2' ? '/ m² ' : '') + (p == null ? 'sans tarif' : C.money(p)) + '</span>';
      b.disabled = p == null;
      b.onclick = function () { addRow(a); };
      $('tiles').appendChild(b);
    });
    $('levels').innerHTML = snap.levels.map(function (l) { return '<label><input type="radio" name="lvl" value="' + l.value + '"' + (l.value === levelValue ? ' checked' : '') + '>' + C.esc(l.label) + (l.surcharge_pct ? ' +' + l.surcharge_pct + ' %' : '') + '</label>'; }).join('');
    $('levels').querySelectorAll('input').forEach(function (r) { r.addEventListener('change', function () { levelValue = r.value; recompute(); }); });
    renderClient(); recompute();
  }

  function paintNet() {
    var on = online();
    $('net').className = 'net' + (on ? ' on' : '');
    $('net-label').textContent = on ? 'En ligne' : 'Hors-ligne';
  }

  async function forget() {
    var pending = (await ordersAll()).filter(function (o) { return o.status === 'pending'; }).length;
    if (pending) { say(pending + ' commande(s) non synchronisée(s) : synchronisez-les avant de retirer ce poste.', 'err'); return; }
    if (!confirm('Retirer ce poste ? Les données locales (clients, tarifs, commandes) seront effacées.')) { return; }
    try { localStorage.removeItem(TOKEN_KEY); } catch (e) {}
    var d = await db(); d.close(); dbp = null;
    await new Promise(function (res) { var r = indexedDB.deleteDatabase('pressing-offline'); r.onsuccess = r.onerror = r.onblocked = res; });
    location.reload();
  }

  // ---------------------------------------------------------------- Démarrage
  async function init() {
    paintNet();
    await loadState();
    var authorised = !!token();
    $('setup').hidden = authorised;
    $('app').hidden = !(authorised && snap);
    if (authorised && !snap) {
      $('msg').innerHTML = '';
      say(online() ? 'Chargement des données du poste…' : 'Ce poste n\'a pas encore de données : connectez-le au réseau une première fois.', online() ? 'ok' : 'warn');
    }
    if (snap) { render(); }
    await renderQueue();
  }

  document.addEventListener('DOMContentLoaded', function () {
    $('btn-register').onclick = register;
    $('btn-refresh').onclick = function () { refreshData(false); };
    $('btn-sync').onclick = function () { syncAll(false); };
    $('btn-forget').onclick = forget;
    $('client-search').addEventListener('input', searchClients);
    $('client-change').onclick = function () { client = null; $('client-search').value = ''; $('client-results').innerHTML = ''; renderClient(); recompute(); $('client-search').focus(); };
    $('nc-add').onclick = addNewClient;
    $('delivery').addEventListener('change', function () { $('delivery-box').hidden = !this.checked; recompute(); });
    $('delivery-address').addEventListener('input', recompute);
    $('submit').onclick = submit;
    $('queue').addEventListener('click', function (e) {
      var b = e.target.closest('button[data-act]'); if (!b) { return; }
      orderGet(b.dataset.n).then(function (o) { if (b.dataset.act === 'ticket') { printTicket(o); } else { printLabels(o.number); } });
    });
    window.addEventListener('online', function () { paintNet(); syncAll(true).then(function () { refreshData(true); }); });
    window.addEventListener('offline', paintNet);
    setInterval(function () { if (online()) { syncAll(true); } }, 60000);

    init().then(function () { if (token() && online()) { refreshData(true); } });

    // Service worker : page et scripts disponibles sans réseau (exige HTTPS ; sans HTTPS, le cache HTTP du navigateur prend le relais)
    if ('serviceWorker' in navigator && window.isSecureContext) { navigator.serviceWorker.register('/sw.js').catch(function () {}); }
  });
})();
