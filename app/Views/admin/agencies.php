<?php use App\Core\Auth; $w = Auth::can('admin', 'update'); ?>
<div class="head"><span class="mono small muted">11</span><h1>Agences</h1></div>
<div class="card scroll"><table class="t">
  <thead><tr><th>Code</th><th>Nom</th><th>Téléphone</th><th>Type</th><?php if ($w): ?><th>Modifier</th><?php endif ?></tr></thead>
  <tbody><?php foreach ($agencies as $a): ?>
    <tr><td class="mono"><?= e($a['code']) ?></td><td class="strong"><?= e($a['name']) ?></td><td><?= e($a['phone'] ?? '—') ?></td><td><?= $a['is_workshop'] ? 'Atelier' : 'Agence' ?></td>
    <?php if ($w): ?><td><form method="post" action="/admin/agences/<?= $a['id'] ?>" class="row" style="gap:6px;flex-wrap:wrap"><?= csrf_field() ?>
      <input class="input mono" name="code" value="<?= e($a['code']) ?>" size="6"><input class="input" name="name" value="<?= e($a['name']) ?>"><input class="input" name="phone" value="<?= e($a['phone'] ?? '') ?>" placeholder="+237 6 ..">
      <label class="small"><input type="checkbox" name="is_workshop" value="1" <?= $a['is_workshop'] ? 'checked' : '' ?>> Atelier</label><input class="input" name="reason" placeholder="Motif" required><button class="btn sm">Enregistrer</button></form></td><?php endif ?></tr>
  <?php endforeach ?></tbody></table></div>
<?php if (Auth::can('admin', 'create')): ?>
<form method="post" action="/admin/agences" class="card pad form"><?= csrf_field() ?><h2>Nouvelle agence</h2>
  <div class="row"><div class="field"><label>Code</label><input class="input mono" name="code" required></div><div class="field"><label>Nom</label><input class="input" name="name" required></div>
  <div class="field"><label>Téléphone</label><input class="input" name="phone"></div><div class="field"><label>&nbsp;</label><label class="small"><input type="checkbox" name="is_workshop" value="1"> Atelier</label></div></div>
  <button class="btn primary">Créer</button></form>
<?php endif ?>
