<?php use App\Services\ClientService; ?>
<div class="head"><span class="mono small muted">02</span><h1>Nouvelle commande</h1><span class="small muted"><?= e(App\Core\Auth::user()['agency_name']) ?> · <?= e(App\Core\Auth::user()['name']) ?></span></div>

<div class="flash warn" id="draft-banner" hidden>Une commande en cours de saisie a été retrouvée sur cet appareil (coupure de connexion ou erreur). <button type="button" class="btn sm" id="draft-restore">Reprendre le brouillon</button> <button type="button" class="btn sm" id="draft-discard">Ignorer</button> <span class="small">Les photos doivent être reprises.</span></div>
<form method="post" action="/commandes" enctype="multipart/form-data" id="order-form" class="split">
  <?= csrf_field() ?>
  <div class="stack">
    <div class="card pad form" id="client-box">
      <input type="hidden" name="client_id" id="client-id" value="<?= e(old('client_id', $client['id'] ?? '')) ?>">
      <div id="client-picked" <?= $client ? '' : 'hidden' ?> style="display:flex;align-items:center;gap:14px">
        <div class="avatar" style="width:42px;height:42px;background:var(--ink)" id="cp-initials"><?= e(initials($client['name'] ?? '')) ?></div>
        <div style="flex:1"><b id="cp-name"><?= e($client['name'] ?? '') ?></b><?= $client ? client_tags($client) : '' ?><div class="mono small muted" id="cp-meta"><?= $client ? e($client['code'] . ' · ' . ClientService::formatPhone($client['phone'])) : '' ?></div><div class="small muted" id="cp-pref"><?= e($client['preferences'] ?? '') ?></div></div>
        <button type="button" class="btn sm" id="client-change">Changer</button>
      </div>
      <div id="client-search-box" <?= $client ? 'hidden' : '' ?> class="form">
        <div class="field"><label>Client</label><input class="input lg" id="client-search" placeholder="Téléphone ou nom du client…" autocomplete="off"></div>
        <div id="client-results" class="stack" style="gap:4px"></div>
        <a class="small" href="/clients/nouveau?retour=commande">+ Créer un nouveau client</a>
      </div>
    </div>

    <div class="field"><span class="label">Ajouter un article</span>
      <div class="tiles">
        <?php foreach ($articles as $a): ?>
          <button type="button" class="tile" data-article="<?= e(json_encode($a, JSON_UNESCAPED_UNICODE)) ?>"><b><?= e($a['name']) ?></b><span><?= $a['unit'] === 'm2' ? '/ m² ' : '' ?><?= money($a['price']) ?></span></button>
        <?php endforeach ?>
      </div>
    </div>

    <div class="card scroll">
      <table class="t" id="lines">
        <thead><tr><th>#</th><th>Article</th><th>Qté / m²</th><th>Traitement</th><th>Marque</th><th>Couleur</th><th>Matière</th><th>Taches / dommages</th><th>Photo</th><th></th></tr></thead>
        <tbody></tbody>
      </table>
      <div class="empty" id="lines-empty">Touchez un article ci-dessus pour l'ajouter.</div>
    </div>
  </div>

  <aside class="stack">
    <div class="card pad form">
      <div class="field"><span class="label">Niveau de service</span>
        <div class="seg">
          <?php foreach ($levels as $l): ?><label><input type="radio" name="service_level" value="<?= $l->value ?>"<?= checked(old('service_level', 'standard') === $l->value) ?>><?= e($l->label()) ?><?= $l->surchargePct() ? ' +' . $l->surchargePct() . '%' : '' ?></label><?php endforeach ?>
        </div>
      </div>
      <div class="kv"><span>Date promise</span><span class="mono" id="q-promised">—</span></div>
      <label class="check"><input type="checkbox" name="delivery" value="1" data-toggle="#delivery-box"<?= checked((bool)old('delivery')) ?>> Livraison à domicile</label>
      <div class="field" id="delivery-box"><label>Adresse de livraison</label><input class="input" name="delivery_address" value="<?= e(old('delivery_address')) ?>"></div>
      <div class="field"><label>Note pour l'atelier</label><textarea class="input" name="notes" rows="2" style="min-height:56px"><?= e(old('notes')) ?></textarea></div>
    </div>

    <div class="card pad">
      <div class="kv"><span id="q-count">0 pièce</span><span class="mono" id="q-subtotal">0</span></div>
      <div class="kv" id="q-surcharge-row" hidden><span id="q-surcharge-label">Majoration</span><span class="mono" id="q-surcharge"></span></div>
      <div class="kv green" id="q-discount-row" hidden><span id="q-discount-label"></span><span class="mono" id="q-discount"></span></div>
      <div class="kv" id="q-delivery-row" hidden><span>Livraison</span><span class="mono" id="q-delivery"></span></div>
      <div class="small muted" id="q-tariff"></div>
      <div class="kv total" style="align-items:baseline"><span>Total</span><span class="mono" style="font-size:24px" id="q-total">0 <small>FCFA</small></span></div>
    </div>

    <div class="card pad form">
      <div class="row">
        <div class="field"><label>Acompte</label><input class="input mono" name="deposit" type="number" min="0" step="50" value="<?= e(old('deposit')) ?>"></div>
        <div class="field"><label>Mode</label><select class="input" name="deposit_method"><?php foreach ($methods as $m): ?><option value="<?= $m->value ?>"<?= selected(old('deposit_method'), $m->value) ?>><?= e($m->label()) ?></option><?php endforeach ?></select></div>
      </div>
      <div class="field"><label>Réf. transaction (Mobile Money)</label><input class="input mono" name="deposit_ref" value="<?= e(old('deposit_ref')) ?>"></div>
    </div>

    <button class="btn primary lg block" id="submit" disabled>Valider &amp; imprimer les étiquettes</button>
    <div class="small muted center" id="blocker">Sélectionnez un client et au moins un article.</div>
  </aside>
</form>

<script>
(function () {
  var TREATMENTS = <?= json_encode($treatments, JSON_UNESCAPED_UNICODE) ?>;
  var form = document.getElementById('order-form');
  var tbody = document.querySelector('#lines tbody');
  var idx = 0;
  var $ = function (id) { return document.getElementById(id); };

  // --- Client
  var search = $('client-search'), results = $('client-results');
  search && search.addEventListener('input', debounce(function () {
    var q = search.value.trim();
    if (q.length < 2) { results.innerHTML = ''; return; }
    fetch('/api/clients?q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (rows) {
      results.innerHTML = rows.length ? '' : '<span class="small muted">Aucun client. Créez-le ci-dessous.</span>';
      rows.forEach(function (c) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'tile'; b.style.minHeight = '48px';
        b.innerHTML = '<b>' + esc(c.name) + (c.is_vip == 1 ? ' <span class="tag vip">VIP</span>' : '') + (c.type === 'pro' ? ' <span class="tag pro">PRO</span>' : '') + '</b><span>' + esc(c.code + ' · ' + c.phone_fmt + ' · ' + c.orders + ' cmd' + (c.balance > 0 ? ' · solde dû ' + c.balance : '')) + '</span>';
        b.onclick = function () { pick(c); };
        results.appendChild(b);
      });
    });
  }, 250));
  var pick = function (c) {
    $('client-id').value = c.id;
    $('cp-name').textContent = c.name;
    $('cp-initials').textContent = c.name.split(/\s+/).slice(0, 2).map(function (p) { return p[0]; }).join('').toUpperCase();
    $('cp-meta').textContent = c.code + ' · ' + c.phone_fmt;
    $('cp-pref').textContent = c.preferences || '';
    $('client-picked').hidden = false; $('client-search-box').hidden = true;
    refresh();
  };
  $('client-change').onclick = function () { $('client-id').value = ''; $('client-picked').hidden = true; $('client-search-box').hidden = false; search.focus(); refresh(); };

  // --- Lignes
  document.querySelectorAll('.tile[data-article]').forEach(function (b) {
    b.addEventListener('click', function () { addLine(JSON.parse(b.getAttribute('data-article'))); });
  });
  function addLine(a) {
    var i = idx++, m2 = a.unit === 'm2';
    var tr = document.createElement('tr');
    tr.dataset.fragile = a.fragile;
    tr.dataset.line = i;
    tr.dataset.article = JSON.stringify(a);
    var n = 'lines[' + i + ']';
    tr.innerHTML =
      '<td class="mono muted seq"></td>' +
      '<td class="nowrap"><b>' + esc(a.name) + '</b>' + (a.fragile == 1 ? ' <span class="badge orange">fragile</span>' : '') + '<input type="hidden" name="' + n + '[article_id]" value="' + a.id + '"></td>' +
      '<td><input class="input mono" style="width:76px" type="number" name="' + n + '[qty]" value="1" min="' + (m2 ? '0.1' : '1') + '" step="' + (m2 ? '0.1' : '1') + '"></td>' +
      '<td><select class="input" style="width:150px" name="' + n + '[treatment_id]">' + TREATMENTS.map(function (t) { return '<option value="' + t.id + '">' + esc(t.label) + '</option>'; }).join('') + '</select></td>' +
      '<td><input class="input" style="width:110px" name="' + n + '[brand]"></td>' +
      '<td><input class="input" style="width:100px" name="' + n + '[color]"></td>' +
      '<td><input class="input" style="width:110px" name="' + n + '[material]"></td>' +
      '<td><input class="input dmg" style="width:170px" name="' + n + '[damages]" placeholder="ex. tache vin devant"></td>' +
      '<td><input type="file" class="photo-in" accept="image/*" capture="environment" name="photos[' + i + ']" style="width:170px;font-size:12px"></td>' +
      '<td><button type="button" class="btn sm" title="Retirer">✕</button></td>';
    tr.querySelector('button').onclick = function () { tr.remove(); refresh(); };
    tr.querySelectorAll('input').forEach(function (inp) { inp.addEventListener('input', refresh); inp.addEventListener('change', refresh); });
    tr.querySelector('.photo-in').addEventListener('change', function () { shrink(this); });
    tbody.appendChild(tr);
    refresh();
  }

  // --- Devis + règles de validation
  form.querySelectorAll('input[name=service_level], input[name=delivery]').forEach(function (r) { r.addEventListener('change', refresh); });
  var photoLines = {};  // lignes dont la photo est exigée par le serveur (fragile, endommagé, valeur)
  var quote = debounce(function () {
    var fd = new FormData(form); fd.delete('photos[]');
    Array.from(fd.keys()).forEach(function (k) { if (k.indexOf('photos[') === 0) fd.delete(k); });
    fetch('/api/devis', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': CSRF } }).then(function (r) { return r.json(); }).then(function (q) {
      if (!q.ok) return;
      $('q-count').textContent = q.count + ' pièce' + (q.count > 1 ? 's' : '') + ' · sous-total';
      $('q-subtotal').textContent = q.subtotal;
      $('q-surcharge-row').hidden = !q.surcharge_pct; $('q-surcharge-label').textContent = 'Majoration +' + q.surcharge_pct + ' %'; $('q-surcharge').textContent = q.surcharge;
      $('q-discount-row').hidden = !q.discount; $('q-discount-label').textContent = q.discount_label || ''; $('q-discount').textContent = q.discount;
      $('q-delivery-row').hidden = !q.delivery_fee; $('q-delivery').textContent = q.delivery_fee;
      $('q-total').innerHTML = esc(q.total) + ' <small>FCFA</small>';
      $('q-promised').textContent = q.promised;
      $('q-tariff').textContent = q.tariffs && q.tariffs.length ? 'Tarif appliqué : ' + q.tariffs.join(', ') : '';
      photoLines = q.photo_lines || {};
      applyPhotoRules();
    }).catch(function () {});
  }, 250);

  // --- Brouillon local : la saisie survit à une coupure de connexion ou à un rechargement (hors photos)
  var DRAFT = 'order-draft', pickedClient = null;
  var _pick = pick;
  pick = function (c) { pickedClient = c; _pick(c); };
  function saveDraft() {
    try {
      var lines = [];
      tbody.querySelectorAll('tr').forEach(function (tr) {
        lines.push({
          article: JSON.parse(tr.dataset.article || 'null'),
          qty: tr.querySelector('input[name$="[qty]"]').value, treatment: tr.querySelector('select[name$="[treatment_id]"]').value, brand: tr.querySelector('input[name$="[brand]"]').value,
          color: tr.querySelector('input[name$="[color]"]').value, material: tr.querySelector('input[name$="[material]"]').value, damages: tr.querySelector('.dmg').value
        });
      });
      if (!pickedClient && !lines.length) { localStorage.removeItem(DRAFT); return; }
      var level = form.querySelector('input[name=service_level]:checked');
      localStorage.setItem(DRAFT, JSON.stringify({
        at: Date.now(), client: pickedClient, lines: lines, level: level ? level.value : 'standard',
        delivery: form.querySelector('input[name=delivery]').checked, address: form.querySelector('[name=delivery_address]').value, notes: form.querySelector('[name=notes]').value
      }));
    } catch (e) {}
  }
  function restoreDraft(d) {
    if (d.client) pick(d.client);
    (d.lines || []).forEach(function (l) {
      if (!l.article) return;
      addLine(l.article);
      var tr = tbody.lastElementChild;
      tr.querySelector('input[name$="[qty]"]').value = l.qty; if (l.treatment) tr.querySelector('select[name$="[treatment_id]"]').value = l.treatment; tr.querySelector('input[name$="[brand]"]').value = l.brand;
      tr.querySelector('input[name$="[color]"]').value = l.color; tr.querySelector('input[name$="[material]"]').value = l.material; tr.querySelector('.dmg').value = l.damages;
    });
    var r = form.querySelector('input[name=service_level][value="' + d.level + '"]'); if (r) r.checked = true;
    var del = form.querySelector('input[name=delivery]'); del.checked = !!d.delivery; del.dispatchEvent(new Event('change'));
    form.querySelector('[name=delivery_address]').value = d.address || ''; form.querySelector('[name=notes]').value = d.notes || '';
    refresh();
  }
  try {
    var saved = JSON.parse(localStorage.getItem(DRAFT) || 'null');
    if (saved && Date.now() - saved.at < 86400000 && ($('client-id').value === '' || saved.client) && (saved.lines || []).length) {
      $('draft-banner').hidden = false;
      $('draft-restore').onclick = function () { $('draft-banner').hidden = true; restoreDraft(saved); };
      $('draft-discard').onclick = function () { $('draft-banner').hidden = true; localStorage.removeItem(DRAFT); };
    }
  } catch (e) {}
  form.addEventListener('input', saveDraft); form.addEventListener('change', saveDraft);
  new MutationObserver(saveDraft).observe(tbody, { childList: true });

  function applyPhotoRules() {
    var rows = tbody.querySelectorAll('tr'), missing = 0, why = '';
    rows.forEach(function (tr, k) {
      var reason = photoLines[tr.dataset.line] || '';
      var needPhoto = tr.dataset.fragile == 1 || tr.querySelector('.dmg').value.trim() !== '' || reason !== '';
      var file = tr.querySelector('.photo-in');
      file.required = needPhoto;
      var lacks = needPhoto && !file.files.length;
      tr.className = lacks ? 'warn' : '';
      if (lacks) { missing++; why = reason || why; }
    });
    var noClient = !$('client-id').value;
    $('submit').disabled = noClient || !rows.length || missing > 0;
    if (!noClient && rows.length) {
      $('blocker').textContent = missing ? 'Bloqué : ' + missing + ' photo(s) obligatoire(s) manquante(s)' + (why ? ' (' + why + ')' : ' (article fragile, endommagé ou de valeur)') + '.' : 'Le client recevra un SMS avec son lien de suivi.';
    }
  }

  // Photos de téléphone : réduites avant envoi (réseau lent, limite du serveur)
  function shrink(input) {
    var f = input.files[0];
    if (!f || f.size < 900000 || !window.DataTransfer) return;
    var img = new Image();
    img.onload = function () {
      var s = Math.min(1, 1600 / Math.max(img.width, img.height)), c = document.createElement('canvas');
      c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      c.toBlob(function (b) {
        if (!b) return;
        var dt = new DataTransfer();
        dt.items.add(new File([b], 'photo.jpg', { type: 'image/jpeg' }));
        input.files = dt.files;
        refresh();
      }, 'image/jpeg', 0.8);
    };
    img.src = URL.createObjectURL(f);
  }

  function refresh() {
    var rows = tbody.querySelectorAll('tr'), missing = 0;
    rows.forEach(function (tr, k) {
      tr.querySelector('.seq').textContent = '-' + String(k + 1).padStart(2, '0');
      var needPhoto = tr.dataset.fragile == 1 || tr.querySelector('.dmg').value.trim() !== '' || !!photoLines[tr.dataset.line];
      var file = tr.querySelector('.photo-in');
      file.required = needPhoto;
      var lacks = needPhoto && !file.files.length;
      tr.className = lacks ? 'warn' : '';
      if (lacks) missing++;
    });
    $('lines-empty').hidden = rows.length > 0;
    var noClient = !$('client-id').value;
    var blocked = noClient || !rows.length || missing > 0;
    $('submit').disabled = blocked;
    $('blocker').textContent = noClient ? 'Sélectionnez un client.' : !rows.length ? 'Ajoutez au moins un article.' : missing ? 'Bloqué : ' + missing + ' photo(s) obligatoire(s) manquante(s) (article fragile ou endommagé).' : 'Le client recevra un SMS avec son lien de suivi.';
    quote();
  }
  refresh();
})();
</script>
