<?php use App\Core\Auth; use App\Domain\Step; $w = Auth::can('admin', 'update'); ?>
<div class="head"><span class="mono small muted">11</span><h1>Parcours de traitement</h1>
  <span class="small muted">Le tri en premier, puis les étapes cochées, puis contrôle qualité, emballage et retrait (toujours obligatoires).</span></div>
<?php foreach ($treatments as $t): $set = explode(',', $t['steps']); ?>
<form method="post" action="/admin/parcours" class="card pad form"><?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
  <div class="row" style="align-items:flex-end;gap:12px;flex-wrap:wrap">
    <div class="field"><label>Libellé</label><input class="input" name="label" value="<?= e($t['label']) ?>" <?= $w ? '' : 'disabled' ?>></div>
    <?php foreach ($steps as $s): ?>
      <label class="check"><input type="checkbox" name="steps[]" value="<?= e($s) ?>" <?= in_array($s, $set, true) ? 'checked' : '' ?> <?= $w ? '' : 'disabled' ?>> <?= e(Step::from($s)->label()) ?></label>
    <?php endforeach ?>
    <label class="check"><input type="checkbox" name="active" value="1" <?= $t['active'] ? 'checked' : '' ?> <?= $w ? '' : 'disabled' ?>> Actif</label>
    <?php if ($w): ?><div class="field"><label>Motif</label><input class="input" name="reason" required placeholder="Motif de la modification"></div><button class="btn sm">Enregistrer</button><?php endif ?>
  </div>
</form>
<?php endforeach ?>
<?php if ($w): ?>
<form method="post" action="/admin/parcours" class="card pad form"><?= csrf_field() ?>
  <h2>Nouveau parcours</h2>
  <div class="row" style="align-items:flex-end;gap:12px;flex-wrap:wrap">
    <div class="field"><label>Libellé</label><input class="input" name="label" required></div>
    <?php foreach ($steps as $s): ?><label class="check"><input type="checkbox" name="steps[]" value="<?= e($s) ?>"> <?= e(Step::from($s)->label()) ?></label><?php endforeach ?>
    <div class="field"><label>Motif</label><input class="input" name="reason" required value="Création"></div>
    <button class="btn primary">Créer</button>
  </div>
</form>
<?php endif ?>
