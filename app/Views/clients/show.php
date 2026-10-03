<?php
use App\Domain\OrderStatus;
use App\Services\ClientService;
?>
<div class="head">
  <div class="avatar lg"><?= e(initials($c['name'])) ?></div>
  <div>
    <h1><?= e($c['name']) ?><?= client_tags($c) ?></h1>
    <span class="mono small muted"><?= e($c['code']) ?> · <?= e(ClientService::formatPhone($c['phone'])) ?> · client depuis <?= e(month_name(substr($c['created_at'], 0, 7))) ?><?= $c['address'] ? ' · ' . e($c['address']) : '' ?></span>
  </div>
  <div class="actions">
    <?php if ($c['preferred_channel'] === 'whatsapp'): ?><a class="btn" href="https://wa.me/<?= e(ltrim($c['phone'], '+')) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif ?>
    <a class="btn" href="tel:<?= e($c['phone']) ?>">Appeler</a>
    <a class="btn" href="/clients/<?= $c['id'] ?>/modifier">Modifier</a>
    <?php if (can('orders')): ?><a class="btn primary" href="/commandes/nouvelle?client=<?= $c['id'] ?>">Nouvelle commande</a><?php endif ?>
  </div>
</div>

<div class="grid g4">
  <div class="card kpi"><span class="l">CA cumulé</span><span class="v" style="font-size:20px"><?= money($stats['revenue']) ?></span><span class="small muted"><?= (int)$stats['n'] ?> commandes</span></div>
  <div class="card kpi"><span class="l">Fréquence</span><span class="v" style="font-size:20px"><?= $freq ? "tous les $freq j" : '—' ?></span><span class="small muted">dernière visite <?= fdate($stats['last'], false) ?></span></div>
  <div class="card kpi"><span class="l">Points fidélité</span><span class="v" style="font-size:20px"><?= money($c['loyalty_points']) ?></span><?php if ($referrer): ?><span class="small muted">parrainé par <a href="/clients/<?= $referrer['id'] ?>"><?= e($referrer['name']) ?></a></span><?php endif ?></div>
  <?php if ($outstanding !== null): ?>
    <div class="card kpi"><span class="l">Encours</span><span class="v <?= $c['credit_limit'] && $outstanding > $c['credit_limit'] ? 'red' : '' ?>" style="font-size:20px"><?= money($outstanding) ?></span><span class="small muted">plafond <?= money($c['credit_limit']) ?> · <?= (int)$c['payment_terms_days'] ?> j</span></div>
  <?php else: ?>
    <div class="card kpi"><span class="l">Réclamations</span><span class="v" style="font-size:20px"><?= count($complaints) ?></span><span class="small muted">sur l'historique</span></div>
  <?php endif ?>
</div>

<div class="split">
  <div class="card">
    <div class="card-h"><h2>Commandes</h2><?php if (can('orders')): ?><a class="small" href="/commandes?q=<?= urlencode($c['phone']) ?>&tab=historique">Tout l'historique</a><?php endif ?></div>
    <?php if (!$orders): ?><div class="empty">Pas encore de commande.</div><?php else: ?>
    <table class="t">
      <thead><tr><th>Commande</th><th>Date</th><th class="num">Pcs</th><th class="num">Montant</th><th class="right">Statut</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): $s = OrderStatus::from($o['status']); $r = risk($o['promised_at'], $o['status']); ?>
        <tr>
          <td class="mono"><a class="row-link" href="/commandes/<?= $o['id'] ?>"><?= e($o['number']) ?></a></td>
          <td><?= dt($o['created_at'], 'd/m/Y') ?></td>
          <td class="num"><?= $o['pcs'] ?></td>
          <td class="num"><?= money($o['total']) ?></td>
          <td class="right"><span class="badge <?= $r === 'red' ? 'red' : $s->tone() ?>"><?= $r === 'red' ? 'En retard' : e($s->label()) ?></span></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <?php endif ?>
  </div>

  <div class="stack">
    <div class="card pad">
      <div class="card-h"><h2>Préférences</h2></div>
      <div class="kv"><span>Canal préféré</span><span><?= e(['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail'][$c['preferred_channel']] ?? $c['preferred_channel']) ?></span></div>
      <?php if ($c['email']): ?><div class="kv"><span>E-mail</span><span><?= e($c['email']) ?></span></div><?php endif ?>
      <?php if ($c['preferences']): ?><div class="note" style="margin-top:8px;white-space:pre-line"><?= e($c['preferences']) ?></div><?php endif ?>
      <?php if ($c['notes']): ?><div class="note warn" style="margin-top:8px;white-space:pre-line"><?= e($c['notes']) ?></div><?php endif ?>
    </div>
    <?php if ($contract): ?>
      <div class="card pad">
        <div class="card-h"><h2>Contrat</h2><span class="small muted">jusqu'au <?= dt($contract['end_date'], 'd/m/Y') ?></span></div>
        <div class="kv"><span>Tarif</span><span><?= e($contract['tariff_label'] ?? '—') ?> · −<?= (float)$contract['discount_pct'] ?> %</span></div>
        <div class="kv"><span>Collecte</span><span><?= e($contract['pickup_schedule'] ?? '—') ?></span></div>
      </div>
    <?php endif ?>
    <?php if ($messages): ?>
      <div class="card pad">
        <div class="card-h"><h2>Derniers messages</h2></div>
        <?php foreach ($messages as $msg): ?>
          <div class="kv small"><span class="mono"><?= dt($msg['created_at'], 'd/m H:i') ?> · <?= e($msg['channel']) ?></span><span class="<?= $msg['status'] === 'envoye' ? 'green' : 'muted' ?>"><?= e($msg['status']) ?></span></div>
          <div class="small muted" style="padding-bottom:6px"><?= e(mb_strimwidth($msg['body'], 0, 110, '…')) ?></div>
        <?php endforeach ?>
      </div>
    <?php endif ?>
  </div>
</div>
