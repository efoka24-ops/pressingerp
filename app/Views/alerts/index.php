<?php
use App\Domain\Step;
$tone = ['critical' => 'red', 'warn' => 'orange', 'info' => ''];
?>
<div class="head"><span class="mono small muted">!</span><h1>Alertes</h1>
  <span class="small muted"><?= count($alerts) ?> ouverte(s) · mise à jour automatique toutes les 30 secondes</span></div>

<div class="card scroll">
  <table class="t">
    <thead><tr><th></th><th>Alerte</th><th>Pour</th><th>Depuis</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($alerts as $a): ?>
      <tr class="<?= $a['level'] === 'critical' ? 'alert' : ($a['level'] === 'warn' ? 'warn' : '') ?>">
        <td><span class="badge <?= $tone[$a['level']] ?? '' ?>"><?= $a['level'] === 'critical' ? 'Critique' : ($a['level'] === 'warn' ? 'À traiter' : 'Info') ?></span></td>
        <td><?= e($a['message']) ?>
          <?php if ($a['escalated_at']): ?><div class="small red">ESCALADE vers <?= e($a['escalated_role']) ?> depuis <?= dt($a['escalated_at'], 'd/m H:i') ?></div><?php endif ?>
          <?php if ($a['ack_at']): ?><div class="small muted">Pris en compte <?= dt($a['ack_at'], 'd/m H:i') ?></div><?php endif ?>
          <?php if ($a['subject_type'] === 'garment'): ?><a class="small" href="/scan?code=<?= urlencode((string)($codes[(int)$a['subject_id']] ?? '')) ?>">voir la pièce</a>
          <?php elseif ($a['subject_type'] === 'order'): ?><a class="small" href="/commandes/<?= (int)$a['subject_id'] ?>">voir la commande</a>
          <?php elseif ($a['subject_type'] === 'step'): ?><a class="small" href="/production">tableau atelier</a><?php endif ?></td>
        <td class="small"><?= e($a['target_role']) ?></td>
        <td class="mono small"><?= dt($a['opened_at'], 'd/m H:i') ?></td>
        <td><?php if (!$a['ack_at'] || $a['event'] === 'cash_variance'): ?>
          <form method="post" action="/alertes/<?= (int)$a['id'] ?>/prise-en-compte"><?= csrf_field() ?><button class="btn sm"><?= $a['event'] === 'cash_variance' ? 'Prendre acte' : 'Prendre en compte' ?></button></form>
        <?php endif ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$alerts): ?><tr><td colspan="5" class="muted">Aucune alerte ouverte. Tout est sous contrôle.</td></tr><?php endif ?>
    </tbody>
  </table>
</div>

<div class="card scroll" style="margin-top:14px">
  <div class="card-h"><h2>Commandes à risque</h2><span class="small muted">Rouge : en retard · Orange : à rendre dans moins de <?= (int)\App\Core\Config::get('risk_orange_hours', 3) ?> h et pas prête</span></div>
  <table class="t">
    <thead><tr><th></th><th>Commande</th><th>Client</th><th class="num">Pièces</th><th>Étape bloquante</th><th>Depuis</th><th>Responsable</th><th>Promise</th></tr></thead>
    <tbody>
    <?php foreach ($risky as $r): ?>
      <tr><td><?= dot($r['risk']) ?></td><td><a class="mono strong" href="/commandes/<?= (int)$r['id'] ?>"><?= e($r['number']) ?></a></td><td><?= e($r['client']) ?></td>
      <td class="num"><?= (int)$r['pcs'] ?></td><td><?= e(Step::from($r['step'])->label()) ?></td><td class="small"><?= since($r['step_since']) ?></td>
      <td class="small"><?= e($r['operator'] ?? 'non pris en charge') ?></td><td class="mono small <?= $r['risk'] === 'red' ? 'red' : 'orange' ?>"><?= fdate($r['promised_at']) ?></td></tr>
    <?php endforeach ?>
    <?php if (!$risky): ?><tr><td colspan="8" class="muted">Aucune commande à risque.</td></tr><?php endif ?>
    </tbody>
  </table>
</div>
<script>
  setTimeout(function reload() { fetch('/api/alertes/compte').then(function () { location.reload(); }).catch(function () { setTimeout(reload, 30000); }); }, 30000);
</script>
