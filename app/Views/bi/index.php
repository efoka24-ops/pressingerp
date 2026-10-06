<?php
$palette = ['#1F5F5B', '#8DB8B2', '#DAD6CC', '#E8B868', '#D97B5E'];
$mixTotal = max(1, array_sum($mix));
$mixLabels = ['pros' => 'Contrats pros', 'particuliers' => 'Pressing particuliers', 'premium' => 'Express / VIP', 'livraison' => 'Livraison'];
$mixColors = ['pros' => '#1F5F5B', 'particuliers' => '#8DB8B2', 'premium' => '#E8B868', 'livraison' => '#DAD6CC'];
$days = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];
$shade = function (int $n) use ($heatMax): string {
    $r = $n / $heatMax;
    return match (true) { $r > .75 => '#1F5F5B', $r > .5 => '#8DB8B2', $r > .25 => '#CFE0DD', $r > 0 => '#E9F1EF', default => '#F4F2EC' };
};
?>
<div class="head">
  <span class="mono small muted">10</span><h1>Business Intelligence</h1><span class="small muted">12 derniers mois</span>
  <div class="actions"><a class="btn" href="/bi/export<?= $ag ? '?agence=' . (int)$ag : '' ?>">Exporter CSV</a></div>
</div>
<?php if ($allAgencies): ?>
<div class="tabs">
  <a href="/bi" class="<?= $ag === 0 ? 'on' : '' ?>">Groupe consolidé</a>
  <?php foreach ($allAgencies as $a): ?><a href="/bi?agence=<?= (int)$a['id'] ?>" class="<?= $ag === (int)$a['id'] ? 'on' : '' ?>"><?= e($a['name']) ?></a><?php endforeach ?>
</div>
<?php endif ?>
<?= partial('home/_fresh', ['fresh' => $fresh]) ?>

<div class="grid g4">
  <div class="card kpi"><span class="l">CA 12 mois</span><span class="v"><?= short_money((int)$kpi['revenue']) ?></span><?php if ($growth !== null): ?><span class="small <?= $growth >= 0 ? 'green' : 'red' ?>"><?= $growth >= 0 ? '▲' : '▼' ?> <?= str_replace('.', ',', (string)abs($growth)) ?> % vs année précédente</span><?php endif ?></div>
  <div class="card kpi"><span class="l">Commandes</span><span class="v"><?= money($kpi['orders']) ?></span><span class="small muted">panier moyen <?= money((int)$kpi['orders'] ? $kpi['revenue'] / $kpi['orders'] : 0) ?></span></div>
  <div class="card kpi"><span class="l">Clients actifs</span><span class="v"><?= money($kpi['clients']) ?></span></div>
  <div class="card kpi"><span class="l">Délai moyen · à l'heure</span><span class="v"><?= (int)round($delay) ?> h</span><span class="small <?= $onTime >= 90 ? 'green' : 'orange' ?>"><?= $onTime ?> % prêtes à la date promise (90 j)</span></div>
</div>

<div class="split" style="grid-template-columns:minmax(0,1.6fr) minmax(0,1fr)">
  <div class="card pad form">
    <div class="card-h"><h2>CA mensuel par agence</h2><span class="small muted">FCFA</span></div>
    <div class="cols">
      <?php foreach ($matrix as $m => $byAgency): $sum = array_sum($byAgency); ?>
        <div class="c" style="height:<?= max(1, (int)round($sum * 100 / $max)) ?>%;align-self:end" title="<?= e(month_name($m)) ?> : <?= money($sum) ?>">
          <?php $i = 0; foreach ($byAgency as $aid => $v): ?><i style="flex:<?= max(0, $v) ?>;background:<?= $palette[$i++ % count($palette)] ?>"></i><?php endforeach ?>
        </div>
      <?php endforeach ?>
    </div>
    <div class="cols" style="height:auto;font:400 10.5px var(--mono);color:var(--muted);text-align:center"><?php foreach (array_keys($matrix) as $m): ?><span><?= e(explode(' ', month_name($m))[0]) ?></span><?php endforeach ?></div>
    <div class="legend"><?php foreach ($agencies as $i => $a): ?><span><i style="background:<?= $palette[$i % count($palette)] ?>"></i><?= e($a['name']) ?></span><?php endforeach ?></div>
  </div>

  <div class="stack">
    <div class="card pad form">
      <div class="card-h"><h2>Mix de CA par service</h2></div>
      <div class="stack-bar" style="height:12px"><?php foreach ($mix as $k => $v): if ($v > 0): ?><div style="flex:<?= $v ?>;background:<?= $mixColors[$k] ?>"></div><?php endif; endforeach ?></div>
      <?php foreach ($mix as $k => $v): ?><div class="kv small" style="padding:0"><span><?= e($mixLabels[$k]) ?></span><span class="mono"><?= pct($v, $mixTotal) ?> %</span></div><?php endforeach ?>
    </div>
    <div class="card pad form">
      <div class="card-h"><h2>Affluence comptoir</h2><span class="small muted">90 j</span></div>
      <div class="heat">
        <span></span><?php foreach ([8, 10, 12, 14, 16, 18] as $h): ?><span class="center"><?= $h ?>h</span><?php endforeach ?>
        <?php foreach ($days as $d => $label): if ($d === 6) continue; ?>
          <span><?= $label ?></span><?php foreach ([8, 10, 12, 14, 16, 18] as $h): $n = $heat[$d][$h] ?? 0; ?><i style="background:<?= $shade($n) ?>" title="<?= $n ?> commandes"></i><?php endforeach ?>
        <?php endforeach ?>
      </div>
    </div>
  </div>
</div>

<div class="grid g2">
  <div class="card scroll">
    <div class="card-h"><h2>Top 10 clients</h2></div>
    <table class="t"><tbody>
      <?php foreach ($top as $i => $c): ?><tr><td class="mono muted"><?= $i + 1 ?></td><td><a class="row-link" href="/clients/<?= $c['id'] ?>"><?= e($c['name']) ?></a><?= client_tags($c) ?></td><td class="num"><?= $c['n'] ?> cmd</td><td class="num"><?= money($c['revenue']) ?></td></tr><?php endforeach ?>
    </tbody></table>
  </div>
  <div class="card pad form">
    <div class="card-h"><h2>Articles les plus traités</h2></div>
    <?php $maxA = max(1, ...array_column($articles, 'n') ?: [1]); foreach ($articles as $a): ?>
      <div class="hbar"><span><?= e($a['label']) ?></span><i style="width:<?= max(3, (int)round($a['n'] * 100 / $maxA)) ?>%"></i><span class="mono right"><?= money($a['n']) ?></span></div>
    <?php endforeach ?>
  </div>
</div>
