<?php use App\Core\Auth; $w = Auth::can('quality', 'validate'); ?>
<div class="head"><span class="mono small muted">05</span><h1>Sinistres</h1><span class="small muted">Pièces perdues ou endommagées. L'indemnité est plafonnée (paramètre « Indemnisation maximale »).</span></div>
<div class="card scroll"><table class="t">
  <thead><tr><th>Pièce</th><th>Client</th><th>Sinistre</th><th class="num">Plafond</th><th>État</th><?php if ($w): ?><th>Décision</th><?php endif ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['status'] === 'declaree' ? 'warn' : '' ?>">
      <td><a class="mono strong" href="/scan?code=<?= urlencode($r['code']) ?>"><?= e($r['code']) ?></a><div class="small"><?= e($r['label']) ?> · <?= money($r['price']) ?> FCFA</div></td>
      <td><?= e($r['client']) ?></td>
      <td><b><?= e($kinds[$r['kind']] ?? $r['kind']) ?></b><div class="small"><?= e($r['description']) ?></div><div class="small muted"><?= e($r['declared_name'] ?? '—') ?> · <?= dt($r['declared_at'], 'd/m H:i') ?></div></td>
      <td class="num mono"><?= money($r['cap']) ?></td>
      <td><?= match ($r['status']) { 'declaree' => 'En attente', 'acceptee' => 'Acceptée · ' . money((int)$r['amount']) . ' FCFA', default => 'Refusée' } ?>
        <?php if ($r['decision_reason']): ?><div class="small muted"><?= e($r['decision_reason']) ?> (<?= e($r['decided_name'] ?? '—') ?>)</div><?php endif ?></td>
      <?php if ($w): ?><td>
        <?php if ($r['status'] === 'declaree'): ?>
        <form method="post" action="/qualite/sinistres/<?= (int)$r['id'] ?>" class="row" style="gap:6px;flex-wrap:wrap"><?= csrf_field() ?>
          <input class="input mono" name="amount" type="number" min="0" max="<?= (int)$r['cap'] ?>" step="100" placeholder="Indemnité" style="width:110px">
          <input class="input" name="reason" placeholder="Motif de la décision" required>
          <button class="btn sm primary" name="decision" value="accepter">Accepter</button><button class="btn sm" name="decision" value="refuser">Refuser</button>
        </form>
        <?php endif ?>
      </td><?php endif ?>
    </tr>
  <?php endforeach ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">Aucun sinistre déclaré.</td></tr><?php endif ?>
  </tbody></table></div>
