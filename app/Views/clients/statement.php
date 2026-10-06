<?php
use App\Domain\PaymentMethod;
use App\Services\ClientService;
$c = $s['client'];
$invoiced = array_sum(array_map(fn($i) => $i['kind'] === 'facture' ? (int)$i['total'] : -(int)$i['total'], $s['invoices']));
$paid = array_sum(array_map(fn($p) => (int)$p['amount'], $s['payments']));
?>
<div class="head no-print">
  <a class="small" href="/clients/<?= (int)$c['id'] ?>">← Fiche client</a><h1>Relevé de compte</h1>
  <div class="actions">
    <form method="get" class="row" style="gap:6px"><input class="input" type="date" name="du" value="<?= e($s['from']) ?>"><input class="input" type="date" name="au" value="<?= e($s['to']) ?>"><button class="btn">Afficher</button><button type="button" class="btn dark" onclick="window.print()">Imprimer / PDF</button></form>
  </div>
</div>
<div class="card pad form">
  <div style="display:flex;justify-content:space-between;gap:20px">
    <div><b><?= e((string)App\Services\SettingsService::get('company.name', 'Pressing')) ?></b><?php $niu = (string)App\Services\SettingsService::get('company.niu', ''); if ($niu !== ''): ?><div class="small muted">NIU : <?= e($niu) ?></div><?php endif ?></div>
    <div class="right"><h1>Relevé de compte</h1><div class="small muted">du <?= dt($s['from'], 'd/m/Y') ?> au <?= dt($s['to'], 'd/m/Y') ?> · édité le <?= date('d/m/Y') ?></div></div>
  </div>
  <div class="note"><b><?= e($c['name']) ?></b> · <?= e($c['code']) ?> · <?= e(ClientService::formatPhone($c['phone'])) ?><?= $c['niu'] ? '<br>NIU : ' . e($c['niu']) : '' ?><?= $c['address'] ? '<br>' . e($c['address']) : '' ?></div>

  <h2>Commandes</h2>
  <table class="t"><thead><tr><th>Date</th><th>Commande</th><th class="num">Montant TTC</th><th class="num">Réglé à la commande</th><th>Mode</th></tr></thead><tbody>
    <?php foreach ($s['orders'] as $o): ?><tr><td><?= dt($o['created_at'], 'd/m/Y') ?></td><td class="mono"><?= e($o['number']) ?></td><td class="num"><?= money($o['total']) ?></td><td class="num"><?= money($o['paid']) ?></td><td><?= $o['on_account'] ? 'En compte' : 'Comptoir' ?></td></tr><?php endforeach ?>
    <?php if (!$s['orders']): ?><tr><td colspan="5" class="muted">Aucune commande sur la période.</td></tr><?php endif ?>
  </tbody></table>

  <?php if ($s['invoices']): ?>
  <h2>Factures et avoirs</h2>
  <table class="t"><thead><tr><th>Date</th><th>Document</th><th>Échéance</th><th class="num">Montant TTC</th><th class="num">Réglé</th></tr></thead><tbody>
    <?php foreach ($s['invoices'] as $i): ?><tr><td><?= dt($i['created_at'], 'd/m/Y') ?></td><td class="mono"><?= e($i['number']) ?><?= $i['kind'] === 'avoir' ? ' (avoir)' : '' ?></td><td><?= $i['kind'] === 'avoir' ? '—' : dt($i['due_date'], 'd/m/Y') ?></td><td class="num"><?= $i['kind'] === 'avoir' ? '−' : '' ?><?= money($i['total']) ?></td><td class="num"><?= $i['kind'] === 'avoir' ? '—' : money($i['paid']) ?></td></tr><?php endforeach ?>
  </tbody></table>
  <?php endif ?>

  <h2>Règlements</h2>
  <table class="t"><thead><tr><th>Date</th><th>Reçu</th><th>Mode</th><th>Référence</th><th class="num">Montant</th></tr></thead><tbody>
    <?php foreach ($s['payments'] as $p): ?><tr><td><?= dt($p['created_at'], 'd/m/Y') ?></td><td class="mono"><?= e($p['receipt_no'] ?? '') ?></td><td><?= e(PaymentMethod::from($p['method'])->label()) ?></td><td class="mono"><?= e($p['reference'] ?? '') ?></td><td class="num"><?= money($p['amount']) ?></td></tr><?php endforeach ?>
    <?php if (!$s['payments']): ?><tr><td colspan="5" class="muted">Aucun règlement sur la période.</td></tr><?php endif ?>
    <tr class="strong"><td colspan="4">Total des règlements</td><td class="num"><?= money($paid) ?> FCFA</td></tr>
  </tbody></table>

  <div class="kv total"><span>Solde dû à ce jour (factures ouvertes et commandes en compte non facturées)</span><span class="mono <?= $s['outstanding'] ? 'orange' : 'green' ?>"><?= money($s['outstanding']) ?> FCFA</span></div>
</div>
