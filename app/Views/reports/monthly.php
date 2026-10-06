<?php $methods = array_combine(array_map(fn($m) => $m->value, App\Domain\PaymentMethod::cases()), array_map(fn($m) => $m->label(), App\Domain\PaymentMethod::cases())); ?>
<?= partial('reports/_head', ['heading' => 'Rapport mensuel · ' . month_name($r['month']), 'kind' => 'mois', 'r' => $r, 'agencyName' => $agencyName, 'agencies' => $agencies]) ?>
<div class="grid g4">
  <div class="card kpi"><span class="l">Chiffre d'affaires</span><span class="v"><?= money($r['revenue']) ?></span><span class="small muted"><?= $r['growth'] === null ? 'pas de mois précédent' : ($r['growth'] >= 0 ? '+' : '') . str_replace('.', ',', (string)$r['growth']) . ' % vs mois précédent' ?></span></div>
  <div class="card kpi"><span class="l">Commandes · pièces</span><span class="v"><?= $r['orders'] ?> · <?= $r['pieces'] ?></span><span class="small muted">panier moyen <?= money($r['basket']) ?></span></div>
  <div class="card kpi"><span class="l">Encaissé (net)</span><span class="v"><?= money($r['cashed']) ?></span><span class="small muted">en compte : <?= money($r['on_account']) ?></span></div>
  <div class="card kpi"><span class="l">Facturé</span><span class="v"><?= money($r['invoiced']) ?></span><span class="small muted">avoirs : <?= money($r['credited']) ?></span></div>
</div>
<div class="split">
  <div class="stack">
    <div class="card pad"><h2>Encaissements par mode</h2>
      <?php foreach ($r['payments'] as $p): ?><div class="kv"><span><?= e($methods[$p['method']] ?? $p['method']) ?></span><span class="mono"><?= money($p['total']) ?></span></div><?php endforeach ?>
      <div class="kv total"><span>Total net</span><span class="mono"><?= money($r['cashed']) ?></span></div>
    </div>
    <div class="card pad"><h2>Qualité et service</h2>
      <div class="kv"><span>Contrôles qualité · taux de reprise</span><span class="mono"><?= $r['checks'] ?> · <?= str_replace('.', ',', (string)$r['rework_rate']) ?> %</span></div>
      <div class="kv"><span>Réclamations ouvertes dans le mois</span><span class="mono"><?= $r['complaints'] ?></span></div>
      <div class="kv"><span>Livraisons effectuées</span><span class="mono"><?= $r['delivered'] ?></span></div>
    </div>
    <?php if ($r['top']): ?><div class="card pad"><h2>Meilleurs clients</h2><?php foreach ($r['top'] as $t): ?><div class="kv"><span><?= e($t['name']) ?> <span class="small muted">· <?= (int)$t['n'] ?> commande(s)</span></span><span class="mono"><?= money($t['revenue']) ?></span></div><?php endforeach ?></div><?php endif ?>
  </div>
  <div class="stack">
    <?php if (!$r['agency']): ?><div class="card pad"><h2>Par agence</h2><?php foreach ($r['agencies'] as $a): ?><div class="kv"><span><?= e($a['name']) ?> <span class="small muted">· <?= (int)$a['n'] ?> commande(s)</span></span><span class="mono"><?= money($a['revenue']) ?></span></div><?php endforeach ?></div><?php endif ?>
    <div class="card scroll"><table class="t"><thead><tr><th>Jour</th><th class="num">Commandes</th><th class="num">CA</th></tr></thead><tbody>
      <?php foreach ($r['days'] as $d): ?><tr><td><?= dt($d['d'], 'd/m') ?></td><td class="num"><?= (int)$d['n'] ?></td><td class="num"><?= money($d['revenue']) ?></td></tr><?php endforeach ?>
      <tr class="strong"><td>Total</td><td class="num"><?= $r['orders'] ?></td><td class="num"><?= money($r['revenue']) ?></td></tr>
    </tbody></table></div>
  </div>
</div>
