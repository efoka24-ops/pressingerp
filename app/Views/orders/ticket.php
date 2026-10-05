<?php
use App\Domain\PaymentMethod;
use App\Domain\ServiceLevel;
use App\Services\PricingService;
use App\Services\SettingsService;

$company = (string)SettingsService::get('company.name');
$niu = (string)SettingsService::get('company.niu');
$rate = rtrim(rtrim((string)SettingsService::get('tax.vat_rate'), '0'), '.');
$paid = array_sum(array_column($payments, 'amount'));
$left = max(0, (int)$o['total'] - (int)$paid);
$counts = [];
foreach ($garments as $g) {
    $k = $g['label'] . '|' . $g['price'];
    $counts[$k] = ($counts[$k] ?? ['label' => $g['label'], 'price' => (int)$g['price'], 'qty' => 0]);
    $counts[$k]['qty']++;
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Ticket <?= e($o['number']) ?></title>
<script src="/assets/vendor/qrcode.min.js"></script>
<style>
  @page { size: 80mm auto; margin: 0; }
  body { width: 72mm; margin: 4mm; font: 11px/1.35 'Courier New', monospace; color: #000; }
  h1 { font-size: 14px; text-align: center; margin: 0; }
  .c { text-align: center; } .r { text-align: right; } hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  table { width: 100%; border-collapse: collapse; } td { vertical-align: top; padding: 1px 0; }
  .tot { font-size: 14px; font-weight: 700; }
  #qr { display: flex; justify-content: center; margin: 6px 0; }
  @media screen { body { background: #fff; box-shadow: 0 0 6px #aaa; padding: 8px; } }
</style>
</head>
<body>
<h1><?= e($company) ?></h1>
<div class="c"><?= e($o['agency']) ?><?= $niu !== '' ? '<br>NIU : ' . e($niu) : '' ?></div>
<hr>
<div>Commande <b><?= e($o['number']) ?></b></div>
<div>Déposé le <?= dt($o['created_at'], 'd/m/Y H:i') ?> par <?= e($o['user'] ?? '—') ?></div>
<div>Client : <b><?= e($o['client']) ?></b> (<?= e($o['client_code']) ?>)</div>
<div>Tél : <?= e(\App\Services\ClientService::formatPhone($o['phone'])) ?></div>
<div>Service <?= e(ServiceLevel::from($o['service_level'])->label()) ?> · à retirer le <b><?= dt($o['promised_at'], 'd/m/Y H:i') ?></b></div>
<hr>
<table>
<?php foreach ($counts as $row): ?>
  <tr><td><?= (int)$row['qty'] ?> × <?= e($row['label']) ?></td><td class="r"><?= money($row['qty'] * $row['price']) ?></td></tr>
<?php endforeach ?>
  <tr><td>Sous-total</td><td class="r"><?= money($o['subtotal']) ?></td></tr>
<?php if ($o['surcharge']): ?><tr><td>Majoration</td><td class="r"><?= money($o['surcharge']) ?></td></tr><?php endif ?>
<?php if ($o['discount']): ?><tr><td><?= e($o['discount_label'] ?? 'Remise') ?></td><td class="r">−<?= money($o['discount']) ?></td></tr><?php endif ?>
<?php if ($o['delivery_fee']): ?><tr><td>Livraison</td><td class="r"><?= money($o['delivery_fee']) ?></td></tr><?php endif ?>
</table>
<hr>
<table>
  <tr class="tot"><td>TOTAL TTC</td><td class="r"><?= money($o['total']) ?> FCFA</td></tr>
  <tr><td>dont TVA <?= e($rate) ?> %</td><td class="r"><?= money(PricingService::vatIncluded((int)$o['total'])) ?></td></tr>
<?php foreach ($payments as $p): ?>
  <tr><td>Payé <?= e(PaymentMethod::tryFrom($p['method'])?->label() ?? $p['method']) ?></td><td class="r"><?= money($p['amount']) ?></td></tr>
<?php endforeach ?>
  <tr class="tot"><td>RESTE À PAYER</td><td class="r"><?= money($left) ?></td></tr>
</table>
<hr>
<div><?= count($garments) ?> pièce<?= count($garments) > 1 ? 's' : '' ?> : <?= e(implode(', ', array_map(fn($g) => substr($g['code'], -2), $garments))) ?> (n° d'étiquette)</div>
<div id="qr"></div>
<div class="c">Suivi en ligne : scannez ou allez sur<br><?= e(tracking_url($o['tracking_token'])) ?></div>
<hr>
<div class="c">Merci de conserver ce ticket : il est exigé au retrait.</div>
<script>
  new QRCode(document.getElementById('qr'), { text: <?= json_encode(tracking_url($o['tracking_token'])) ?>, width: 110, height: 110, correctLevel: QRCode.CorrectLevel.M });
  window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
</script>
</body>
</html>
