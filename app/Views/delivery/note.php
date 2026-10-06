<?php use App\Services\ClientService; ?>
<div class="head no-print"><a class="small" href="/livraisons/<?= (int)$d['id'] ?>">← Livraison</a><h1>Bon de livraison</h1><div class="actions"><button class="btn dark" onclick="window.print()">Imprimer / PDF</button></div></div>
<div class="card pad form">
  <div style="display:flex;justify-content:space-between;gap:20px">
    <div><b><?= e((string)App\Services\SettingsService::get('company.name', 'Pressing')) ?></b><div class="small muted"><?= e($agency) ?></div></div>
    <div class="right"><h1>Bon de livraison <?= e($order['number'] ?? '#' . $d['id']) ?></h1><div class="small muted">édité le <?= date('d/m/Y H:i') ?></div></div>
  </div>
  <div class="note"><b><?= e($client['name']) ?></b> · <?= e(ClientService::formatPhone($d['phone'])) ?><br>Adresse de livraison : <?= e($d['address']) ?><br>Créneau : <?= $d['slot_at'] ? dt($d['slot_at'], 'd/m/Y H:i') : 'à convenir' ?><?= $driver ? ' · Livreur : ' . e($driver) : '' ?></div>
  <table class="t"><thead><tr><th>Désignation</th><th>Code pièce</th><th class="num">Qté</th></tr></thead><tbody>
    <?php foreach ($garments as $g): ?><tr><td><?= e($g['label']) ?></td><td class="mono"><?= e($g['code']) ?></td><td class="num"><?= e(rtrim(rtrim(number_format((float)$g['qty'], 2, ',', ''), '0'), ',')) ?></td></tr><?php endforeach ?>
  </tbody></table>
  <?php if ($order): ?><div class="kv total"><span>Reste à payer à la livraison</span><span class="mono"><?= $balance > 0 ? money($balance) . ' FCFA' : ((int)$order['on_account'] ? 'En compte' : 'Réglé') ?></span></div><?php endif ?>
  <div class="row" style="margin-top:28px;gap:40px">
    <div class="field" style="flex:1"><label>Remis par (livreur) — nom et signature</label><div style="border-bottom:1px solid var(--ink);height:48px"></div></div>
    <div class="field" style="flex:1"><label>Reçu par le client — nom, signature, date</label><div style="border-bottom:1px solid var(--ink);height:48px"></div></div>
  </div>
  <div class="small muted">Le client vérifie le nombre de pièces à la remise. Toute réserve est notée avant la signature.</div>
</div>
