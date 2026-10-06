<div class="head no-print"><a class="small" href="/stocks">← Stocks</a><h1>Bon de commande fournisseur</h1><div class="actions"><button class="btn dark" onclick="window.print()">Imprimer / PDF</button></div></div>
<div class="card pad form">
  <div style="display:flex;justify-content:space-between;gap:20px">
    <div><b><?= e((string)App\Services\SettingsService::get('company.name', 'Pressing')) ?></b><?php $niu = (string)App\Services\SettingsService::get('company.niu', ''); if ($niu !== ''): ?><div class="small muted">NIU : <?= e($niu) ?></div><?php endif ?></div>
    <div class="right"><h1>Bon de commande n° BC-<?= str_pad((string)$po['id'], 5, '0', STR_PAD_LEFT) ?></h1><div class="small muted">émis le <?= dt($po['created_at'], 'd/m/Y') ?><?= $po['user'] ? ' par ' . e($po['user']) : '' ?></div></div>
  </div>
  <div class="note">Fournisseur : <b><?= e($po['supplier'] ?: 'à préciser') ?></b></div>
  <table class="t"><thead><tr><th>Désignation</th><th class="num">Quantité commandée</th><th>Unité</th><th class="num">En stock</th></tr></thead><tbody>
    <tr><td><?= e($po['item']) ?></td><td class="num strong"><?= e(rtrim(rtrim(number_format((float)$po['qty'], 2, ',', ' '), '0'), ',')) ?></td><td><?= e($po['unit']) ?></td><td class="num"><?= e(rtrim(rtrim(number_format((float)$po['quantity'], 2, ',', ' '), '0'), ',')) ?></td></tr>
  </tbody></table>
  <div class="small">Statut : <?= $po['status'] === 'recue' ? 'reçue le ' . dt($po['received_at'], 'd/m/Y') : 'commandée, en attente de réception' ?></div>
  <div class="row" style="margin-top:28px;gap:40px">
    <div class="field" style="flex:1"><label>Pour le pressing — nom et signature</label><div style="border-bottom:1px solid var(--ink);height:48px"></div></div>
    <div class="field" style="flex:1"><label>Pour le fournisseur — bon pour accord</label><div style="border-bottom:1px solid var(--ink);height:48px"></div></div>
  </div>
</div>
