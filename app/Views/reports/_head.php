<?php
// En-tête commun des rapports : filtres (masqués à l'impression) et identité de l'entreprise
?>
<div class="head no-print">
  <a class="small" href="/cockpit">← Cockpit</a><h1><?= e($heading) ?></h1>
  <div class="actions">
    <form method="get" class="row" style="gap:6px">
      <?php if ($agencies): ?><select class="input" name="agence"><option value="0">Groupe consolidé</option><?php foreach ($agencies as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$r['agency'] === (int)$a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach ?></select><?php endif ?>
      <input class="input" type="<?= $kind === 'jour' ? 'date' : 'month' ?>" name="<?= $kind === 'jour' ? 'date' : 'mois' ?>" value="<?= e($kind === 'jour' ? $r['date'] : $r['month']) ?>">
      <button class="btn">Afficher</button>
      <button type="button" class="btn dark" onclick="window.print()">Imprimer / PDF</button>
    </form>
  </div>
</div>
<div class="card pad" style="margin-bottom:12px">
  <b><?= e((string)App\Services\SettingsService::get('company.name', 'Pressing')) ?></b> · <?= e($agencyName) ?>
  <?php $niu = (string)App\Services\SettingsService::get('company.niu', ''); if ($niu !== ''): ?> · NIU <?= e($niu) ?><?php endif ?>
  <div class="small muted">Édité le <?= date('d/m/Y à H:i') ?> · montants en FCFA, TTC</div>
</div>
