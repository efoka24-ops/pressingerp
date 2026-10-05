<?php
use App\Domain\PaymentMethod;
use App\Services\SettingsService;

$company = (string)SettingsService::get('company.name');
$niu = (string)SettingsService::get('company.niu');
$reversal = $lines[0]['kind'] === 'reversal';
$total = array_sum(array_column($lines, 'amount'));
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Reçu <?= e($lines[0]['receipt_no']) ?></title>
<style>
  @page { size: 80mm auto; margin: 0; }
  body { width: 72mm; margin: 4mm; font: 11px/1.4 'Courier New', monospace; color: #000; }
  h1 { font-size: 14px; text-align: center; margin: 0; } .c { text-align: center; } .r { text-align: right; }
  hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; } table { width: 100%; border-collapse: collapse; } td { padding: 1px 0; vertical-align: top; }
  .tot { font-size: 14px; font-weight: 700; } .box { border: 2px solid #000; padding: 3px; text-align: center; font-weight: 700; margin: 4px 0; }
</style>
</head>
<body>
<h1><?= e($company) ?></h1>
<div class="c"><?= e($agency) ?><?= $niu !== '' ? '<br>NIU : ' . e($niu) : '' ?></div>
<div class="box"><?= $reversal ? 'AVOIR DE CAISSE — ANNULATION' : 'REÇU DE PAIEMENT' ?></div>
<div>N° <b><?= e($lines[0]['receipt_no']) ?></b></div>
<div>Le <?= dt($lines[0]['created_at'], 'd/m/Y H:i') ?> · agent <?= e($lines[0]['agent'] ?? '—') ?></div>
<div>Client : <b><?= e($lines[0]['client']) ?></b></div>
<div>Commande : <b><?= e($lines[0]['number'] ?? '—') ?></b></div>
<hr>
<table>
<?php foreach ($lines as $l): ?>
  <tr><td><?= e(PaymentMethod::tryFrom($l['method'])?->label() ?? $l['method']) ?><?= $l['reference'] ? '<br><small>' . e($l['reference']) . '</small>' : '' ?></td><td class="r"><?= $l['amount'] < 0 ? '−' : '' ?><?= money(abs((int)$l['amount'])) ?></td></tr>
<?php endforeach ?>
</table>
<hr>
<table><tr class="tot"><td><?= $reversal ? 'TOTAL RENDU' : 'TOTAL REÇU' ?></td><td class="r"><?= money(abs((int)$total)) ?> FCFA</td></tr>
<?php if ($order): ?>
  <tr><td>Total commande</td><td class="r"><?= money($order['total']) ?></td></tr>
  <tr><td>Déjà réglé</td><td class="r"><?= money($order['paid']) ?></td></tr>
  <tr class="tot"><td>RESTE À PAYER</td><td class="r"><?= money(max(0, (int)$order['total'] - (int)$order['paid'])) ?></td></tr>
<?php endif ?>
</table>
<?php if ($reversal): ?><hr><div>Motif : <?= e($lines[0]['reason']) ?></div><div>Autorisé par : <?= e($authoriser ?? '—') ?></div><?php endif ?>
<hr>
<div class="c">Merci de votre confiance.</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
