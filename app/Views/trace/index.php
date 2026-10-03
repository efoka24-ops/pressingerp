<?php
use App\Domain\GarmentStatus;
use App\Domain\Step;
?>
<div class="head">
  <span class="mono small muted">03</span><h1>Traçabilité</h1>
  <form method="get" class="row" style="flex:1;max-width:520px"><div class="field"><input class="input mono" name="q" value="<?= e($q) ?>" placeholder="N° de pièce (PR-…-03) ou de commande (PR-…)" autofocus></div><button class="btn dark">Tracer</button></form>
</div>

<?php if ($garment):
  $cur = Step::from($garment['step']);
  $gs = GarmentStatus::from($garment['status']); ?>
  <div class="head">
    <h2 style="font-size:20px"><?= e($garment['label']) ?><?= $garment['color'] ? ' · ' . e($garment['color']) : '' ?></h2>
    <span class="small muted"><?= e($garment['client']) ?> · reçu <?= dt($garment['order_created']) ?> · <?= e($garment['agency']) ?> · <a href="/commandes/<?= $garment['order_id'] ?>"><?= e($garment['number']) ?></a></span>
    <span class="badge <?= $gs->tone() ?: 'orange' ?>" style="margin-left:auto"><?= e($cur->label()) ?> · <?= e($gs->label()) ?> · <?= since($garment['step_since']) ?></span>
  </div>
  <div class="card pad">
    <?php $steps = Step::cases(); ?>
    <div class="stepper" style="--n:<?= count($steps) ?>">
      <?php
      $times = [];
      foreach (array_reverse($events) as $ev) { if ($ev['action'] === 'entree' || $ev['action'] === 'reception') $times[$ev['step']] = $ev['created_at']; }
      foreach ($steps as $s): $cls = $s->index() < $cur->index() ? 'done' : ($s === $cur ? ($s === Step::Retire ? 'done' : 'cur') : 'todo'); ?>
        <div class="<?= $cls ?>"><span><?= e($s->label()) ?></span><span class="mono small muted"><?= isset($times[$s->value]) && $cls !== 'todo' ? dt($times[$s->value], 'H:i') : '' ?></span></div>
      <?php endforeach ?>
    </div>
  </div>
  <div class="card scroll">
    <div class="card-h"><h2>Journal des événements</h2><span class="small muted"><?= count($events) ?> événements</span></div>
    <table class="t">
      <thead><tr><th>Date</th><th>Étape</th><th>Événement</th><th>Machine / note</th><th>Opérateur</th></tr></thead>
      <tbody>
      <?php foreach ($events as $ev): ?>
        <tr class="<?= in_array($ev['action'], ['incident', 'reprise'], true) ? 'alert' : '' ?>">
          <td class="mono muted nowrap"><?= dt($ev['created_at'], 'd/m H:i') ?></td>
          <td><?= e(Step::from($ev['step'])->label()) ?></td>
          <td><?= e(event_label($ev['action'])) ?></td>
          <td><?= e(implode(' · ', array_filter([$ev['machine'], $ev['note']]))) ?></td>
          <td><?= e($ev['user'] ?? 'automatique') ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?php elseif ($order): ?>
  <div class="card scroll">
    <div class="card-h"><h2>Commande <?= e($order['number']) ?> · <?= e($order['client']) ?></h2><a class="small" href="/commandes/<?= $order['id'] ?>">Ouvrir la commande</a></div>
    <table class="t"><tbody>
      <?php foreach ($garments as $g): $gs = GarmentStatus::from($g['status']); ?>
        <tr><td class="mono"><a href="/tracabilite?q=<?= urlencode($g['code']) ?>"><?= e($g['code']) ?></a></td><td><?= e($g['label']) ?></td><td><?= e(Step::from($g['step'])->label()) ?></td><td><span class="badge <?= $gs->tone() ?>"><?= e($gs->label()) ?></span></td><td class="mono small muted"><?= since($g['step_since']) ?></td></tr>
      <?php endforeach ?>
    </tbody></table>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-h"><h2>Derniers incidents et reprises</h2></div>
    <?php if (!$recent): ?><div class="empty">Aucun incident récent.</div><?php else: ?>
    <table class="t"><tbody>
      <?php foreach ($recent as $ev): ?>
        <tr><td class="mono muted nowrap"><?= dt($ev['created_at'], 'd/m H:i') ?></td><td class="mono"><a href="/tracabilite?q=<?= urlencode($ev['code']) ?>"><?= e($ev['code']) ?></a></td><td><?= e($ev['label']) ?></td><td><span class="badge red"><?= e(event_label($ev['action'])) ?></span></td><td><?= e($ev['note']) ?></td><td><?= e($ev['user'] ?? '') ?></td></tr>
      <?php endforeach ?>
    </tbody></table>
    <?php endif ?>
  </div>
<?php endif ?>
