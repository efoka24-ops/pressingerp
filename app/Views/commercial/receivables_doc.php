<div class="head no-print"><a class="small" href="/recouvrement">← Recouvrement</a><h1>État des créances</h1><div class="actions"><button class="btn dark" onclick="window.print()">Imprimer / PDF</button></div></div>
<div class="card pad form">
  <div style="display:flex;justify-content:space-between;gap:20px">
    <div><b><?= e((string)App\Services\SettingsService::get('company.name', 'Pressing')) ?></b><?php $niu = (string)App\Services\SettingsService::get('company.niu', ''); if ($niu !== ''): ?><div class="small muted">NIU : <?= e($niu) ?></div><?php endif ?></div>
    <div class="right"><h1>État des créances</h1><div class="small muted">arrêté au <?= date('d/m/Y à H:i') ?> · montants en FCFA TTC</div></div>
  </div>
  <table class="t">
    <thead><tr><th>Client</th><?php foreach ($buckets as $l): ?><th class="num"><?= e($l) ?></th><?php endforeach ?><th class="num">Total dû</th><th class="num">Retard max.</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['name']) ?></td><?php foreach (array_keys($buckets) as $k): ?><td class="num"><?= $r[$k] ? money($r[$k]) : '—' ?></td><?php endforeach ?><td class="num strong"><?= money($r['balance']) ?></td><td class="num"><?= $r['days_late'] ?> j</td></tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">Aucune créance en cours.</td></tr><?php endif ?>
    <tr class="strong"><td>Total</td><?php foreach (array_keys($buckets) as $k): ?><td class="num"><?= money($totals[$k]) ?></td><?php endforeach ?><td class="num"><?= money($total) ?></td><td></td></tr>
    </tbody>
  </table>
  <div class="small muted">Non échu : factures non échues et commandes en compte pas encore facturées. Les tranches comptent les jours écoulés depuis l'échéance.</div>
</div>
