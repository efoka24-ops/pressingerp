<?php
use App\Domain\GarmentStatus;
use App\Domain\Step;
$maxCount = max(1, ...array_values(array_map(fn($c) => $c['step'] === Step::Pret ? 0 : $c['count'], $columns)));
?>
<div class="head">
  <span class="mono small muted">04</span><h1>Production</h1>
  <span class="small muted">Atelier · <?= count($operators) ?> opérateurs · <?= $throughput ?> pièces traitées sur la dernière heure</span>
  <div class="actions"><a class="btn dark" href="/scan">Scanner une pièce</a></div>
</div>

<div class="chips">
  <?php foreach ($operators as $op): ?><span class="badge <?= $op['busy'] ? 'green' : '' ?>"><?= e($op['name']) ?> · <?= $op['busy'] ? $op['busy'] . ' en cours' : 'disponible' ?></span><?php endforeach ?>
</div>

<div class="board">
  <?php foreach ($columns as $key => $col): $s = $col['step']; $hot = $s !== Step::Pret && $col['count'] === $maxCount && $col['count'] >= 10; ?>
    <section class="colm <?= $hot ? 'hot' : '' ?>" id="<?= e($key) ?>">
      <h3><span><?= e($s->label()) ?></span><span class="mono muted"><?= $col['count'] ?></span></h3>
      <span class="small muted"><b><?= $col['available'] ?></b> disponible(s) · <?= $col['active'] ?> en cours</span> <?php if ($col['blocked']): ?><span class="small red"><?= $col['blocked'] ?> bloquée(s)</span><?php endif ?>
      <?php foreach ($col['items'] as $p):
        $r = risk($p['promised_at']);
        $gs = GarmentStatus::from($p['status']);
        $cls = $gs === GarmentStatus::Bloque || $gs === GarmentStatus::AReprendre || $r === 'red' ? 'red' : ($r === 'orange' || $p['service_level'] === 'express' ? 'orange' : ''); ?>
        <div class="piece <?= $cls ?>">
          <a class="mono strong" href="/scan?code=<?= urlencode($p['code']) ?>" style="color:var(--ink)"><?= e($p['code']) ?></a>
          <span><?= e($p['label']) ?> · <?= e($p['client']) ?><?= $p['is_vip'] ? ' <span class="tag vip">VIP</span>' : '' ?></span>
          <span class="<?= $cls ?: 'muted' ?>"><?= e($gs->label()) ?><?= $p['operator'] ? ' · ' . e($p['operator']) : '' ?> · <?= since($p['step_since']) ?><?= $p['rework_count'] ? ' · reprise' : '' ?></span>
          <span class="muted">Promis <?= fdate($p['promised_at']) ?></span>
          <?php if (!in_array($s, [Step::Controle, Step::Pret, Step::Emballage], true) && $gs !== GarmentStatus::Bloque): ?>
            <?php if (can('production', 'update')): ?><div class="acts">
              <?php if ($gs !== GarmentStatus::EnCours): ?>
                <form method="post" action="/pieces/<?= $p['id'] ?>/action"><?= csrf_field() ?><input type="hidden" name="do" value="start"><input type="hidden" name="back" value="production"><button class="btn sm">Prendre</button></form>
              <?php endif ?>
              <form method="post" action="/pieces/<?= $p['id'] ?>/action"><?= csrf_field() ?><input type="hidden" name="do" value="complete"><input type="hidden" name="back" value="production"><button class="btn sm primary">Terminer</button></form>
            </div><?php endif ?>
          <?php elseif ($s === Step::Controle && can('quality')): ?>
            <div class="acts"><a class="btn sm primary" href="/qualite/controle/<?= $p['id'] ?>">Contrôler</a></div>
          <?php endif ?>
        </div>
      <?php endforeach ?>
      <?php if ($col['count'] > count($col['items'])): ?><span class="small muted">+ <?= $col['count'] - count($col['items']) ?> autres</span><?php endif ?>
    </section>
  <?php endforeach ?>
</div>
