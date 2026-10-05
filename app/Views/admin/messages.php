<?php use App\Core\Auth; use App\Services\ConsentService; $w = Auth::can('admin', 'update'); $names = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail']; ?>
<div class="head"><span class="mono small muted">11</span><h1>Messagerie</h1>
  <span class="small muted">Messages envoyés aux clients : confirmation de dépôt, commande prête, retard, livraison, remise.</span></div>

<div class="grid g2">
  <div class="card pad">
    <h2>Canaux d'envoi</h2>
    <?php foreach ($channels as $ch => $ok): ?>
      <div class="kv"><span><?= e($names[$ch]) ?></span><span class="<?= $ok ? 'green' : 'orange' ?>"><?= $ok ? 'Configuré' : 'Non configuré' ?></span></div>
    <?php endforeach ?>
    <div class="small muted" style="margin-top:8px">Un canal non configuré est ignoré : le message passe au canal suivant (préféré du client → SMS → WhatsApp → e-mail). Sans aucun canal, il reste en attente et une alerte prévient l'administrateur.</div>
    <?php if (!empty($mail['host'])): ?>
      <div class="small" style="margin-top:8px">E-mail : <b><?= e($mail['from'] ?? '') ?></b> via <?= e($mail['host']) ?>:<?= (int)($mail['port'] ?? 587) ?> (<?= e(strtoupper((string)($mail['secure'] ?? 'tls'))) ?>)</div>
      <?php if ($w): ?>
      <form method="post" action="/admin/messages/test-smtp" style="margin-top:8px"><?= csrf_field() ?><button class="btn sm">Tester la connexion (aucun message envoyé)</button></form>
      <form method="post" action="/admin/messages/test-envoi" class="row" style="gap:6px;margin-top:8px"><?= csrf_field() ?>
        <input class="input" type="email" name="to" placeholder="Votre adresse pour un e-mail de test" required><button class="btn sm">Envoyer un test</button></form>
      <?php endif ?>
    <?php endif ?>
  </div>
  <div class="card pad">
    <h2>7 derniers jours</h2>
    <?php foreach ($stats as $s): ?><div class="kv"><span><?= e(['envoye' => 'Envoyés', 'echec' => 'Échecs', 'en_attente' => 'En attente', 'annule' => 'Annulés'][$s['status']] ?? $s['status']) ?></span><span class="mono"><?= (int)$s['n'] ?></span></div><?php endforeach ?>
    <?php if (!$stats): ?><span class="small muted">Aucun message.</span><?php endif ?>
  </div>
</div>

<?php foreach ($templates as $t): $vars = $events[$t['event']] ?? []; ?>
<form method="post" action="/admin/messages/modele" class="card pad form"><?= csrf_field() ?>
  <input type="hidden" name="event" value="<?= e($t['event']) ?>">
  <h2><?= e($t['label']) ?></h2>
  <div class="field"><textarea class="input" name="body" rows="2" <?= $w ? '' : 'disabled' ?>><?= e($t['body']) ?></textarea>
    <div class="small muted">Variables : <?= implode(' ', array_map(fn($v) => '<code>{' . e($v) . '}</code>', $vars)) ?></div></div>
  <?php if ($w): ?>
  <div class="row" style="gap:8px;align-items:center"><label class="check"><input type="checkbox" name="active" value="1" <?= $t['active'] ? 'checked' : '' ?>> Envoyer ce message</label>
    <input class="input" name="reason" placeholder="Motif de la modification" required><button class="btn sm">Enregistrer</button></div>
  <?php endif ?>
</form>
<?php endforeach ?>

<div class="card scroll"><div class="card-h"><h2>Derniers messages</h2></div>
  <table class="t"><thead><tr><th>Date</th><th>Client</th><th>Canal</th><th>Événement</th><th>État</th></tr></thead><tbody>
  <?php foreach ($recent as $m): ?>
    <tr><td class="mono small"><?= dt($m['created_at'], 'd/m H:i') ?></td><td><?= e($m['client']) ?></td><td><?= e($names[$m['channel']] ?? $m['channel']) ?></td><td><?= e($m['event'] ?? '—') ?></td>
    <td class="<?= $m['status'] === 'envoye' ? 'green' : ($m['status'] === 'echec' ? 'red' : 'orange') ?>"><?= e($m['status']) ?><?= $m['error'] ? ' · ' . e($m['error']) : '' ?></td></tr>
  <?php endforeach ?>
  <?php if (!$recent): ?><tr><td colspan="5" class="muted">Aucun message.</td></tr><?php endif ?>
  </tbody></table></div>
