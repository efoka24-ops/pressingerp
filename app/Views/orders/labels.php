<?php use App\Domain\ServiceLevel; ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Étiquettes <?= e($o['number']) ?></title>
<script src="/assets/vendor/qrcode.min.js"></script>
<style>
  @page { size: 62mm 40mm; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'IBM Plex Sans', Arial, sans-serif; color: #000; }
  .l { width: 62mm; height: 40mm; padding: 3mm; display: flex; gap: 3mm; page-break-after: always; overflow: hidden; }
  .qr { width: 26mm; height: 26mm; flex: none; }
  .qr img, .qr canvas { width: 26mm !important; height: 26mm !important; }
  .t { display: flex; flex-direction: column; gap: 1mm; font-size: 8pt; line-height: 1.2; min-width: 0; }
  .c { font: 700 9pt 'IBM Plex Mono', monospace; }
  .s { font-weight: 700; font-size: 9pt; }
  .m { color: #333; }
  @media screen { body { background: #eee; padding: 10px; display: flex; flex-wrap: wrap; gap: 10px; } .l { background: #fff; } }
</style>
</head>
<body>
<?php foreach ($garments as $g): ?>
  <div class="l">
    <div class="qr" data-code="<?= e($g['code']) ?>"></div>
    <div class="t">
      <span class="c"><?= e($g['code']) ?></span>
      <span class="s"><?= e($g['label']) ?></span>
      <span class="m"><?= e($o['client']) ?></span>
      <span class="m"><?= e(implode(' · ', array_filter([$g['color'], $g['material']]))) ?></span>
      <span><?= e(ServiceLevel::from($o['service_level'])->label()) ?> · <?= dt($o['promised_at'], 'd/m H:i') ?></span>
      <span class="m"><?= (int)$g['seq'] ?> / <?= count($garments) ?><?= $g['damages'] ? ' · ⚠ ' . e(mb_strimwidth($g['damages'], 0, 30, '…')) : '' ?></span>
    </div>
  </div>
<?php endforeach ?>
<script>
  document.querySelectorAll('.qr').forEach(function (el) { new QRCode(el, { text: el.dataset.code, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M }); });
  window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
</script>
</body>
</html>
