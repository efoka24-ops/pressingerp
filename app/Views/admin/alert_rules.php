<?php use App\Core\Auth; use App\Domain\Role; $w = Auth::can('admin', 'update'); ?>
<div class="head"><span class="mono small muted">11</span><h1>Règles d'alerte</h1>
  <span class="small muted">Qui est alerté, après quel délai, et vers qui l'alerte monte si personne ne la prend en compte.</span></div>
<?php foreach ($rules as $r): ?>
<form method="post" action="/admin/alertes" class="card pad form"><?= csrf_field() ?>
  <input type="hidden" name="event" value="<?= e($r['event']) ?>">
  <div class="row" style="align-items:flex-end;gap:10px;flex-wrap:wrap">
    <div style="min-width:260px"><b><?= e($r['label']) ?></b><div class="small muted mono"><?= e($r['event']) ?></div></div>
    <div class="field"><label>Priorité</label><select class="input" name="priority" <?= $w ? '' : 'disabled' ?>><?php foreach (['normal' => 'Normale', 'high' => 'Élevée', 'critical' => 'Critique'] as $k => $v): ?><option value="<?= $k ?>" <?= $r['priority'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Destinataire</label><select class="input" name="target_role" <?= $w ? '' : 'disabled' ?>><?php foreach (Role::cases() as $ro): ?><option value="<?= $ro->value ?>" <?= $r['target_role'] === $ro->value ? 'selected' : '' ?>><?= e($ro->label()) ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Délai (min)</label><input class="input mono" type="number" min="0" name="delay_minutes" value="<?= (int)$r['delay_minutes'] ?>" style="width:90px" <?= $w ? '' : 'disabled' ?>></div>
    <div class="field"><label>Escalade vers</label><select class="input" name="escalate_role" <?= $w ? '' : 'disabled' ?>><option value="">— aucune —</option><?php foreach (Role::cases() as $ro): ?><option value="<?= $ro->value ?>" <?= $r['escalate_role'] === $ro->value ? 'selected' : '' ?>><?= e($ro->label()) ?></option><?php endforeach ?></select></div>
    <div class="field"><label>après (min)</label><input class="input mono" type="number" min="1" name="escalate_after_minutes" value="<?= e((string)($r['escalate_after_minutes'] ?? '')) ?>" style="width:90px" <?= $w ? '' : 'disabled' ?>></div>
    <label class="check"><input type="checkbox" name="active" value="1" <?= $r['active'] ? 'checked' : '' ?> <?= $w ? '' : 'disabled' ?>> Active</label>
    <?php if ($w): ?><div class="field"><label>Motif</label><input class="input" name="reason" required></div><button class="btn sm">Enregistrer</button><?php endif ?>
  </div>
</form>
<?php endforeach ?>
