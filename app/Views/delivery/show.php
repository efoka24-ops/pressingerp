<?php
use App\Core\Auth;
use App\Services\ClientService;
use App\Services\DeliveryService;
$tone = ['a_collecter' => 'orange', 'collecte' => '', 'en_traitement' => '', 'a_livrer' => 'orange', 'en_route' => 'blue', 'livre' => 'green', 'non_livre' => 'red'];
$id = (int)$d['id'];
$staff = !$driver && Auth::can('delivery', 'update');
$tel = preg_replace('/[^\d+]/', '', (string)$d['phone']);
$closed = $d['status'] === 'livre';
$manager = Auth::isManager();
?>
<div class="head"><a class="small" href="/livraisons">← <?= $driver ? 'Ma tournée' : 'Livraisons' ?></a>
  <h1><?= $d['kind'] === 'collect' ? 'Collecte' : 'Livraison' ?> · <?= e($order['number'] ?? '#' . $id) ?></h1>
  <span class="badge <?= $tone[$d['status']] ?? '' ?>"><?= e(DeliveryService::STATUS[$d['status']] ?? $d['status']) ?></span></div>

<div class="split">
<div class="stack">
  <div class="card pad">
    <div class="kv"><span>Client</span><span class="right"><b><?= e($client['name']) ?></b> · <a href="tel:<?= e($tel) ?>"><?= e(ClientService::formatPhone($d['phone'])) ?></a></span></div>
    <div class="kv"><span>Adresse</span><span class="right"><?= e($d['address']) ?></span></div>
    <div class="kv"><span>Créneau</span><span class="right"><?= $d['slot_at'] ? dt($d['slot_at'], 'd/m/Y H:i') : '—' ?></span></div>
    <?php if ($d['notes']): ?><div class="kv"><span>Précisions</span><span class="right"><?= e($d['notes']) ?></span></div><?php endif ?>
    <?php if ($order): ?>
      <div class="kv"><span>Commande</span><span class="right"><a href="/commandes/<?= (int)$order['id'] ?>"><?= e($order['number']) ?></a> · <?= count($garments) ?> ligne(s)</span></div>
      <div class="kv total"><span>Reste à payer</span><span class="mono"><?= $balance > 0 ? money($balance, true) : ((int)$order['on_account'] ? 'En compte' : 'Réglé') ?></span></div>
    <?php endif ?>
    <?php if ($d['driver_id']): ?><div class="kv"><span>Livreur</span><span class="right"><?= e(array_column($drivers, 'name', 'id')[$d['driver_id']] ?? App\Core\Database::value('SELECT name FROM users WHERE id = ?', [$d['driver_id']])) ?></span></div><?php endif ?>
  </div>

  <?php if ($d['kind'] === 'collect' && $d['status'] === 'a_collecter' && ($driver || $staff)): ?>
  <form method="post" action="/livraisons/<?= $id ?>/collectee" class="card pad"><?= csrf_field() ?>
    <button class="btn primary lg block">J'ai récupéré les vêtements</button></form>
  <?php endif ?>

  <?php if ($d['kind'] === 'collect' && $d['status'] === 'collecte' && !$driver && Auth::can('orders', 'create')): ?>
  <div class="card pad"><a class="btn primary lg block" href="/commandes/nouvelle?collecte=<?= $id ?>">Créer la commande de cette collecte</a></div>
  <?php endif ?>

  <?php if ($d['kind'] === 'deliver' && in_array($d['status'], ['a_livrer', 'non_livre'], true) && ($driver || $staff)): ?>
  <form method="post" action="/livraisons/<?= $id ?>/depart" class="card pad"><?= csrf_field() ?>
    <?php if (!$d['driver_id']): ?><div class="small red">Affectez d'abord un livreur.</div><?php endif ?>
    <button class="btn primary lg block"<?= $d['driver_id'] ? '' : ' disabled' ?>>Partir livrer<?= (int)$d['attempts'] > 0 ? ' (nouvelle tentative)' : '' ?></button>
    <div class="small muted">Le client reçoit par message son code de remise à 4 chiffres.</div></form>
  <?php endif ?>

  <?php if ($d['status'] === 'en_route' && ($driver || $staff)): ?>
  <form method="post" action="/livraisons/<?= $id ?>/livrer" enctype="multipart/form-data" class="card pad form" id="complete-form"><?= csrf_field() ?>
    <h2>Remise au client</h2>
    <div class="field"><label>Code donné par le client (4 chiffres)</label><input class="input lg mono" name="code" inputmode="numeric" maxlength="4" autocomplete="off" placeholder="0000"></div>
    <div class="field"><label>… ou signature du client</label>
      <canvas id="sig" width="600" height="180" style="width:100%;max-width:600px;height:180px;border:1px dashed var(--line2);border-radius:8px;touch-action:none;background:#fff"></canvas>
      <input type="hidden" name="signature" id="sig-data">
      <button type="button" class="btn sm" id="sig-clear">Effacer</button></div>
    <div class="field"><label>… ou photo de la remise</label><input class="input" type="file" name="photo" accept="image/*" capture="environment"></div>

    <?php if ($balance > 0): ?>
    <h2>Solde à encaisser : <?= money($balance, true) ?></h2>
    <?php foreach ([0, 1] as $n): ?>
    <div class="row">
      <div class="field"><label><?= $n === 0 ? 'Montant' : 'Autre montant' ?></label><input class="input mono" name="lines[<?= $n ?>][amount]" type="number" min="0" max="<?= $balance ?>" step="50" <?= $n === 0 ? 'value="' . $balance . '"' : 'placeholder="optionnel"' ?>></div>
      <div class="field"><label>Mode</label><select class="input" name="lines[<?= $n ?>][method]"><option value="especes">Espèces</option><option value="orange"<?= $n === 1 ? ' selected' : '' ?>>Orange Money</option><option value="mtn">MTN MoMo</option></select></div>
      <div class="field"><label>Réf. Mobile Money</label><input class="input mono" name="lines[<?= $n ?>][reference]" style="width:130px"></div>
    </div>
    <?php endforeach ?>
    <label class="check"><input type="checkbox" name="defer" value="1" data-toggle="#defer-box"> Le client ne paie pas tout maintenant (report du solde)</label>
    <div id="defer-box" hidden class="stack">
      <div class="field"><label>Motif du report</label><input class="input" name="defer_reason" placeholder="Pourquoi le solde n'est pas encaissé"></div>
      <?php if (!$manager): ?><div class="row"><div class="field"><label>Responsable : identifiant</label><input class="input" name="auth_login" autocomplete="off"></div><div class="field"><label>Mot de passe</label><input class="input" type="password" name="auth_password" autocomplete="off"></div></div><?php endif ?>
    </div>
    <?php endif ?>
    <button class="btn dark lg block">Valider la livraison</button>
  </form>

  <form method="post" action="/livraisons/<?= $id ?>/echec" class="card pad form"><?= csrf_field() ?>
    <h2>Livraison impossible</h2>
    <div class="row">
      <div class="field"><label>Motif</label><select class="input" name="reason" required><?php foreach (DeliveryService::FAIL_REASONS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Nouveau créneau</label><input class="input" type="datetime-local" name="slot" required></div>
    </div>
    <div class="field"><label>Précisions</label><input class="input" name="note"></div>
    <button class="btn">Enregistrer l'échec et prévenir le client</button>
  </form>
  <?php endif ?>

  <?php if ($staff && !$closed): ?>
  <form method="post" action="/livraisons/<?= $id ?>/affecter" class="card pad form"><?= csrf_field() ?>
    <h2>Affectation</h2>
    <div class="row">
      <div class="field"><label>Livreur</label><select class="input" name="driver_id" required><option value="">— choisir —</option><?php foreach ($drivers as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === (int)$d['driver_id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Créneau</label><input class="input" type="datetime-local" name="slot" value="<?= $d['slot_at'] && strtotime($d['slot_at']) > time() ? e(date('Y-m-d\TH:i', strtotime($d['slot_at']))) : '' ?>" required></div>
    </div>
    <button class="btn">Affecter</button>
  </form>
  <form method="post" action="/livraisons/<?= $id ?>/adresse" class="card pad form"><?= csrf_field() ?>
    <h2>Corriger l'adresse</h2>
    <div class="field"><label>Nouvelle adresse</label><input class="input" name="address" value="<?= e($d['address']) ?>" required></div>
    <div class="field"><label>Motif (ex. client joint par téléphone)</label><input class="input" name="reason" required></div>
    <button class="btn">Corriger</button>
  </form>
  <?php endif ?>
</div>

<div class="stack">
  <div class="card pad">
    <h2>Historique</h2>
    <?php foreach ($events as $ev): ?>
      <div class="kv small"><span><?= dt($ev['created_at'], 'd/m H:i') ?> · <?= e(DeliveryService::STATUS[$ev['status']] ?? $ev['status']) ?><?= $ev['note'] ? '<br><span class="muted">' . e($ev['note']) . '</span>' : '' ?></span><span class="muted"><?= e($ev['user'] ?? '') ?></span></div>
    <?php endforeach ?>
  </div>
  <?php if ($proofs): ?>
  <div class="card pad">
    <h2>Preuves de remise</h2>
    <?php foreach ($proofs as $p): ?>
      <div class="kv small"><span><?= ['code' => 'Code client validé', 'signature' => 'Signature', 'photo' => 'Photo'][$p['kind']] ?? e($p['kind']) ?></span>
        <span><?php if ($p['kind'] !== 'code'): ?><a href="<?= e($p['data']) ?>" target="_blank" rel="noopener">voir</a><?php else: ?>✓<?php endif ?></span></div>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>
</div>

<script>
(function () {
  var cv = document.getElementById('sig');
  if (!cv) return;
  var ctx = cv.getContext('2d'), drawing = false, dirty = false;
  ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
  function pos(ev) { var r = cv.getBoundingClientRect(); return { x: (ev.clientX - r.left) * cv.width / r.width, y: (ev.clientY - r.top) * cv.height / r.height }; }
  cv.addEventListener('pointerdown', function (ev) { drawing = true; var p = pos(ev); ctx.beginPath(); ctx.moveTo(p.x, p.y); cv.setPointerCapture(ev.pointerId); });
  cv.addEventListener('pointermove', function (ev) { if (!drawing) return; var p = pos(ev); ctx.lineTo(p.x, p.y); ctx.stroke(); dirty = true; });
  ['pointerup', 'pointercancel'].forEach(function (n) { cv.addEventListener(n, function () { drawing = false; }); });
  document.getElementById('sig-clear').addEventListener('click', function () { ctx.clearRect(0, 0, cv.width, cv.height); dirty = false; });
  document.getElementById('complete-form').addEventListener('submit', function () { document.getElementById('sig-data').value = dirty ? cv.toDataURL('image/png') : ''; });
})();
</script>
