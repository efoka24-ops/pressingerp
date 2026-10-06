<?php $methods = array_combine(array_map(fn($m) => $m->value, App\Domain\PaymentMethod::cases()), array_map(fn($m) => $m->label(), App\Domain\PaymentMethod::cases())); ?>
<?= partial('reports/_head', ['heading' => 'Rapport journalier du ' . dt($r['date'], 'd/m/Y'), 'kind' => 'jour', 'r' => $r, 'agencyName' => $agencyName, 'agencies' => $agencies]) ?>
<div class="grid g4">
  <div class="card kpi"><span class="l">Chiffre d'affaires</span><span class="v"><?= money($r['revenue']) ?></span><span class="small muted"><?= $r['orders'] ?> commande(s) · <?= $r['pieces'] ?> pièce(s)</span></div>
  <div class="card kpi"><span class="l">Panier moyen</span><span class="v"><?= money($r['basket']) ?></span><span class="small muted"><?= $r['new_clients'] ?> nouveau(x) client(s)</span></div>
  <div class="card kpi"><span class="l">Encaissé (net)</span><span class="v"><?= money($r['cashed']) ?></span><span class="small muted">facturé en compte : <?= money($r['invoiced']) ?></span></div>
  <div class="card kpi"><span class="l">Écart de caisse</span><span class="v <?= $r['variance'] ? 'red' : '' ?>"><?= $r['variance'] > 0 ? '+' : '' ?><?= money($r['variance']) ?></span><span class="small muted"><?= count($r['sessions']) ?> caisse(s) clôturée(s)</span></div>
</div>
<div class="split">
  <div class="card pad"><h2>Encaissements par mode</h2>
    <?php foreach ($r['payments'] as $p): ?><div class="kv"><span><?= e($methods[$p['method']] ?? $p['method']) ?> <span class="small muted">· <?= (int)$p['n'] ?> opération(s)</span></span><span class="mono"><?= money($p['total']) ?></span></div><?php endforeach ?>
    <?php if (!$r['payments']): ?><span class="small muted">Aucun encaissement.</span><?php endif ?>
    <div class="kv total"><span>Total net</span><span class="mono"><?= money($r['cashed']) ?></span></div>
  </div>
  <div class="card pad"><h2>Activité</h2>
    <div class="kv"><span>Commandes prêtes</span><span class="mono"><?= $r['ready'] ?></span></div>
    <div class="kv"><span>Commandes remises (retrait ou livraison)</span><span class="mono"><?= $r['collected'] ?></span></div>
    <div class="kv"><span>Livraisons effectuées</span><span class="mono"><?= $r['delivered'] ?></span></div>
    <div class="kv"><span>Commandes en compte (CA)</span><span class="mono"><?= money($r['on_account']) ?></span></div>
    <div class="kv"><span>Commandes annulées</span><span class="mono"><?= $r['cancelled'] ?></span></div>
    <div class="kv"><span>Reprises qualité · incidents · réclamations</span><span class="mono"><?= $r['reworks'] ?> · <?= $r['incidents'] ?> · <?= $r['complaints'] ?></span></div>
  </div>
</div>
<?php if ($r['sessions']): ?>
<div class="card scroll"><table class="t"><thead><tr><th>Caisse</th><th>Agent</th><th>Agence</th><th class="num">Écart</th><th>Justification</th></tr></thead><tbody>
  <?php foreach ($r['sessions'] as $s): ?><tr class="<?= (int)$s['variance'] ? 'alert' : '' ?>"><td><?= e($s['label']) ?></td><td><?= e($s['agent']) ?></td><td><?= e($s['agency']) ?></td><td class="num"><?= money($s['variance']) ?></td><td class="small"><?= e($s['justification'] ?? '') ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?php endif ?>
