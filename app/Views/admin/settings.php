<?php use App\Core\Auth; $w = Auth::can('admin', 'update'); ?>
<div class="head"><span class="mono small muted">11</span><h1>Paramètres</h1><span class="small muted">Chaque modification crée une version et une ligne d'audit.</span></div>
<?php foreach ($groups as $group => $items): ?>
<div class="card pad"><h2><?= e($group) ?></h2>
  <?php foreach ($items as $it): ?>
    <form method="post" action="/admin/parametres" class="row" style="gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0"><?= csrf_field() ?>
      <input type="hidden" name="key" value="<?= e($it['key']) ?>">
      <div style="min-width:320px"><b><?= e($it['label']) ?></b><div class="small muted mono"><?= e($it['key']) ?> · défaut <?= e((string)$it['default']) ?></div></div>
      <?php if ($it['type'] === 'bool'): ?><select class="input" name="value" <?= $w ? '' : 'disabled' ?>><option value="1" <?= $it['value'] ? 'selected' : '' ?>>Oui</option><option value="0" <?= $it['value'] ? '' : 'selected' ?>>Non</option></select>
      <?php else: ?><input class="input mono" name="value" value="<?= e((string)$it['value']) ?>" <?= $w ? '' : 'disabled' ?>><?php endif ?>
      <?php if ($w): ?><input class="input" name="reason" placeholder="Motif" required><button class="btn sm">Enregistrer</button><?php endif ?>
      <a class="small" href="/admin/parametres?histo=<?= e($it['key']) ?>">historique</a>
    </form>
  <?php endforeach ?>
</div>
<?php endforeach ?>
<?php if ($history): ?>
<div class="card scroll"><div class="card-h"><h2>Historique</h2></div><table class="t"><thead><tr><th>Date</th><th>Valeur</th><th>Par</th><th>Motif</th></tr></thead><tbody>
<?php foreach ($history as $h): ?><tr><td class="mono"><?= e($h['created_at']) ?></td><td class="mono"><?= e($h['value']) ?></td><td><?= e($h['user_name'] ?? '—') ?></td><td><?= e($h['reason'] ?? '') ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?php endif ?>
