<?php
use App\Domain\PaymentMethod;
use App\Services\SettingsService;

$open = $session['status'] === 'ouverte';
$sum = fn($rows, $key) => array_sum(array_map(fn($r) => (int)$r[$key], $rows));
$totalExpected = array_sum($st['expected']);
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>État de caisse n° <?= (int)$session['id'] ?></title>
<style>
  @page { size: A4; margin: 14mm; }
  body { font: 12px/1.45 Arial, sans-serif; color: #000; margin: 0; }
  h1 { font-size: 18px; margin: 0 0 2px; } h2 { font-size: 13px; margin: 16px 0 4px; border-bottom: 1px solid #000; }
  table { width: 100%; border-collapse: collapse; } th, td { padding: 3px 6px; border-bottom: 1px solid #ccc; text-align: left; }
  .n { text-align: right; font-family: 'Courier New', monospace; } .bad { color: #b00020; font-weight: 700; } .muted { color: #555; }
  .sign { display: flex; gap: 40px; margin-top: 40px; } .sign div { flex: 1; border-top: 1px solid #000; padding-top: 4px; }
  @media screen { body { max-width: 900px; margin: 16px auto; padding: 0 12px; } }
</style>
</head>
<body>
<h1>État de caisse n° <?= (int)$session['id'] ?> — <?= e($session['label']) ?></h1>
<div class="muted"><?= e(SettingsService::get('company.name')) ?> · <?= e($st['agency']) ?> · agent <?= e($st['agent']) ?></div>
<div>Ouverte le <?= dt($session['opened_at'], 'd/m/Y H:i') ?> · <?= $open ? '<b>EN COURS</b> (état provisoire)' : 'clôturée le ' . dt($session['closed_at'], 'd/m/Y H:i') ?> · fond de caisse <?= money($session['opening_float']) ?> FCFA</div>

<h2>Encaissements et annulations</h2>
<table>
  <tr><th>Heure</th><th>Reçu</th><th>Commande</th><th>Client</th><th>Mode</th><th class="n">Montant</th></tr>
  <?php foreach ($st['payments'] as $p): ?>
    <tr class="<?= $p['kind'] === 'reversal' ? 'bad' : '' ?>"><td><?= dt($p['created_at'], 'H:i') ?></td><td><?= e($p['receipt_no']) ?></td><td><?= e($p['number'] ?? '—') ?></td><td><?= e($p['client']) ?></td>
    <td><?= e(PaymentMethod::tryFrom($p['method'])?->label() ?? $p['method']) ?><?= $p['kind'] === 'reversal' ? ' (annulation : ' . e($p['reason']) . ')' : '' ?></td><td class="n"><?= money($p['amount']) ?></td></tr>
  <?php endforeach ?>
  <?php if (!$st['payments']): ?><tr><td colspan="6" class="muted">Aucun encaissement.</td></tr><?php endif ?>
</table>

<h2>Dépenses en espèces</h2>
<table>
  <?php foreach ($st['expenses'] as $x): ?><tr><td><?= dt($x['created_at'], 'H:i') ?></td><td><?= e($x['label']) ?></td><td class="n">−<?= money($x['amount']) ?></td></tr><?php endforeach ?>
  <?php if (!$st['expenses']): ?><tr><td class="muted">Aucune dépense.</td></tr><?php endif ?>
</table>

<h2>Théorique, compté et écart (aucune tolérance)</h2>
<table>
  <tr><th>Mode</th><th class="n">Théorique</th><th class="n">Compté</th><th class="n">Écart</th></tr>
  <?php foreach ($st['expected'] as $method => $exp): $c = $st['counts'][$method]['counted'] ?? null; $d = $c === null ? null : (int)$c - (int)$exp; ?>
    <tr><td><?= e(PaymentMethod::tryFrom($method)?->label() ?? $method) ?></td><td class="n"><?= money($exp) ?></td><td class="n"><?= $c === null ? '—' : money($c) ?></td><td class="n <?= $d ? 'bad' : '' ?>"><?= $d === null ? '—' : (($d > 0 ? '+' : '') . money($d)) ?></td></tr>
  <?php endforeach ?>
  <tr><th>Total</th><th class="n"><?= money($totalExpected) ?></th><th class="n"><?= $open ? '—' : money(array_sum(array_map(fn($r) => (int)$r['counted'], $st['counts']))) ?></th><th class="n <?= (int)$session['variance'] ? 'bad' : '' ?>"><?= $open ? '—' : (((int)$session['variance'] > 0 ? '+' : '') . money($session['variance'])) ?></th></tr>
</table>
<?php if ($session['justification']): ?><p><b>Justification de l'écart :</b> <?= e($session['justification']) ?></p><?php endif ?>

<h2>Chiffre d'affaires de la période (agence)</h2>
<div><?= (int)$st['orders']['n'] ?> commande(s) pour <?= money($st['orders']['total']) ?> FCFA, dont <?= money($st['orders']['paid']) ?> encaissés ; reste à encaisser <?= money((int)$st['orders']['total'] - (int)$st['orders']['paid']) ?> FCFA.</div>

<div class="sign"><div>L'agent de caisse</div><div>Le responsable</div></div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
