<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Codes à écrire — <?= e($o['number']) ?></title>
<style>
  body { font: 14px/1.4 Arial, sans-serif; margin: 24px; color: #000; }
  h1 { font-size: 18px; margin: 0 0 4px; }
  table { border-collapse: collapse; width: 100%; margin-top: 12px; }
  th, td { border: 1px solid #000; padding: 6px 8px; text-align: left; }
  .code { font: 700 16px 'Courier New', monospace; }
  .note { margin-top: 12px; font-size: 12px; }
</style>
</head>
<body>
<h1>Étiquetage manuel — commande <?= e($o['number']) ?></h1>
<div>Client : <b><?= e($o['client']) ?></b> · <?= e($o['client_code']) ?> · à rendre le <?= dt($o['promised_at'], 'd/m/Y H:i') ?></div>
<table>
  <thead><tr><th>Code à écrire sur l'étiquette</th><th>Vêtement</th><th>Détails</th></tr></thead>
  <tbody>
  <?php foreach ($garments as $g): ?>
    <tr><td class="code"><?= e($g['code']) ?></td><td><?= e($g['label']) ?></td><td><?= e(implode(' · ', array_filter([$g['color'], $g['material'], $g['damages'] ? 'dommage : ' . $g['damages'] : null]))) ?></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<p class="note">Imprimante indisponible : écrivez chaque code lisiblement sur l'étiquette. Les codes sont déjà enregistrés ; réimprimez les étiquettes QR dès que l'imprimante fonctionne (motif « étiquetage manuel »).</p>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
