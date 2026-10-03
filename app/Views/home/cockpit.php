<?php
use App\Domain\Step;
$t = $today;
$m = $money;
$ob = $objectives;
?>
<div class="head">
  <h1>Aujourd'hui</h1>
  <div class="tabs">
    <a href="/cockpit" class="<?= $ag === 0 ? 'on' : '' ?>">Groupe consolidé</a>
    <?php foreach ($agencies as $a): ?><a href="/cockpit?agence=<?= (int)$a['id'] ?>" class="<?= $ag === (int)$a['id'] ? 'on' : '' ?>"><?= e($a['name']) ?></a><?php endforeach ?>
  </div>
  <span class="small muted" style="margin-left:auto">Comparé à la moyenne des 4 derniers <?= e(day_name()) ?>s</span>
</div>

<div class="grid g5">
  <div class="card kpi dark">
    <span class="l">CA du jour</span>
    <span class="v" style="font-size:30px"><?= money($t['revenue']) ?> <small>FCFA</small></span>
    <?php if ($t['target']): $p = min(100, pct($t['revenue'], $t['target'])); ?>
      <div class="bar"><i style="width:<?= $p ?>%"></i></div>
      <div class="kv small" style="padding:0"><span>Objectif <?= money($t['target']) ?></span><span class="mono" style="color:#fff"><?= $p ?> %</span></div>
    <?php else: ?><?= delta($t['revenue'], $t['ref_revenue']) ?><?php endif ?>
  </div>
  <div class="card kpi"><span class="l">Commandes</span><span class="v"><?= $t['orders'] ?></span><?= delta($t['orders'], $t['ref_orders']) ?></div>
  <div class="card kpi"><span class="l">Pièces reçues</span><span class="v"><?= $t['pieces'] ?></span><?= delta($t['pieces'], $t['ref_pieces']) ?></div>
  <div class="card kpi"><span class="l">Panier moyen</span><span class="v"><?= money($t['basket']) ?></span><?= delta($t['basket'], $t['ref_basket']) ?></div>
  <div class="card kpi"><span class="l">Clients du jour</span><span class="v"><?= $t['clients'] ?></span><span class="small muted"><?= $t['new_clients'] ?> nouveau<?= $t['new_clients'] > 1 ? 'x' : '' ?></span></div>
</div>

<div class="split">
  <div class="stack">
    <div class="card pad form">
      <div class="card-h"><h2>Carte de production — pièces par étape</h2><span class="small muted"><?= $prod['total'] ?> pièces en atelier</span></div>
      <?php $n = count($prod['steps']); ?>
      <div class="map" style="--n:<?= $n ?>">
        <?php foreach ($prod['steps'] as $k => $s):
          $h = max(2, (int)round($s['n'] * 100 / $prod['max']));
          $cls = $k === $prod['bottleneck'] ? 'hot' : ($k === 'pret' ? 'ready' : ($s['blocked'] || $s['stale'] > 5 ? 'warm' : '')); ?>
          <a class="<?= $cls ?>" href="/production#<?= e($k) ?>" title="<?= $s['blocked'] ?> bloquée(s) · <?= $s['stale'] ?> en attente longue"><b><?= $s['n'] ?></b><i style="height:<?= $h ?>%"></i></a>
        <?php endforeach ?>
      </div>
      <div class="map-l" style="--n:<?= $n ?>">
        <?php foreach ($prod['steps'] as $k => $s): ?><span class="<?= $k === $prod['bottleneck'] ? 'red strong' : '' ?>"><?= e($s['label']) ?></span><?php endforeach ?>
      </div>
      <?php if ($prod['bottleneck']): $b = $prod['steps'][$prod['bottleneck']]; ?>
        <div class="note err" style="display:flex;gap:10px;align-items:center"><?= dot('red') ?>
          <span>Goulot : <?= e($b['label']) ?> à <?= $prod['load_pct'] ?> % de la charge moyenne<?= $b['stale'] ? ' — ' . $b['stale'] . ' pièces attendent depuis plus de 3 h' : '' ?>.</span>
          <a href="/production#<?= e($prod['bottleneck']) ?>" style="margin-left:auto;color:#7A231B;font-weight:600">Réaffecter des opérateurs →</a>
        </div>
      <?php endif ?>
    </div>

    <div class="card">
      <div class="card-h"><h2>Commandes à risque</h2><span class="small muted"><?= count(array_filter($risky, fn($o) => risk($o['promised_at']) === 'red')) ?> rouges · <?= count(array_filter($risky, fn($o) => risk($o['promised_at']) === 'orange')) ?> orange</span></div>
      <?php if (!$risky): ?><div class="empty">Aucune commande en retard ou à risque.</div><?php else: ?>
      <div class="scroll"><table class="t">
        <thead><tr><th></th><th>Commande</th><th>Client</th><th class="num">Pcs</th><th>Étape bloquante</th><th>Attente</th><th>Responsable</th><th class="num">Promis</th></tr></thead>
        <tbody>
        <?php foreach ($risky as $o): $r = risk($o['promised_at']); $w = $o['worst']; ?>
          <tr>
            <td><?= dot($r) ?></td>
            <td class="mono"><a class="row-link" href="/commandes/<?= $o['id'] ?>"><?= e($o['number']) ?></a></td>
            <td><?= e($o['client']) ?><?= client_tags($o) ?></td>
            <td class="num"><?= $o['pcs'] ?></td>
            <td><?php if ($w): ?><?= e(Step::from($w['step'])->label()) ?><?php if (in_array($w['status'], ['bloque', 'a_reprendre'], true)): ?> · <span class="red"><?= e(App\Domain\GarmentStatus::from($w['status'])->label()) ?></span><?php endif ?><?php endif ?></td>
            <td class="mono <?= $r ?>"><?= $w ? since($w['step_since']) : '—' ?></td>
            <td><?= e($w['operator'] ?? '—') ?></td>
            <td class="num <?= $r === 'red' ? 'red' : '' ?>"><?= fdate($o['promised_at']) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table></div>
      <?php endif ?>
    </div>
  </div>

  <div class="stack">
    <div class="card pad">
      <div class="card-h"><h2>Alertes managériales</h2><span class="mono small muted"><?= count($alerts) ?> active<?= count($alerts) > 1 ? 's' : '' ?></span></div>
      <?php if (!$alerts): ?><span class="small muted">Rien à signaler.</span><?php endif ?>
      <div class="alerts">
        <?php foreach ($alerts as [$tone, $title, $detail, $href]): ?>
          <a href="<?= e($href) ?>"><?= dot($tone) ?><div><b><?= e($title) ?></b><span class="muted"><?= e($detail) ?></span></div></a>
        <?php endforeach ?>
      </div>
    </div>
    <div class="grid g2">
      <div class="card kpi"><span class="l">Encaissé</span><span class="v" style="font-size:18px"><?= money($m['cashed']) ?></span><span class="small muted">dont Mobile Money <?= $m['momo_share'] ?> %</span></div>
      <div class="card kpi"><span class="l">Créances</span><span class="v" style="font-size:18px"><?= short_money($m['receivables']) ?></span><span class="small <?= $m['receivables_old'] ? 'red' : 'muted' ?>"><?= short_money($m['receivables_old']) ?> &gt; 60 j</span></div>
      <div class="card kpi"><span class="l">Reprises</span><span class="v" style="font-size:18px"><?= $m['reworks'] ?></span><span class="small muted"><?= $m['incidents'] ?> incident(s) · <?= $m['complaints'] ?> réclamation(s)</span></div>
      <div class="card kpi"><span class="l">Non retirées</span><span class="v" style="font-size:18px"><?= $m['uncollected'] ?></span><span class="small muted"><?= money($m['uncollected_amount']) ?> FCFA · <?= $m['uncollected_old'] ?> &gt; J+15</span></div>
    </div>
    <div class="card pad form">
      <div class="card-h"><h2>Objectifs — <?= e(month_name(date('Y-m'))) ?></h2><span class="small muted">J<?= $ob['day'] ?> / <?= $ob['days'] ?></span></div>
      <?php if ($ob['ca_target']): $p = pct($ob['ca'], $ob['ca_target']); $exp = pct($ob['day'], $ob['days']); ?>
        <div class="field"><div class="kv small" style="padding:0"><span>CA</span><span class="mono"><?= short_money($ob['ca']) ?> / <?= short_money($ob['ca_target']) ?> · <?= $p ?> %</span></div>
          <div class="bar" style="position:relative;overflow:visible"><i class="<?= $p < $exp * .9 ? 'orange' : '' ?>" style="width:<?= min(100, $p) ?>%"></i><span style="position:absolute;left:<?= $exp ?>%;top:-3px;width:1px;height:12px;background:var(--ink)" title="Rythme attendu"></span></div></div>
      <?php endif ?>
      <?php if ($ob['rec_target']): $p = pct($ob['recovered'], $ob['rec_target']); ?>
        <div class="field"><div class="kv small" style="padding:0"><span>Recouvrement</span><span class="mono"><?= short_money($ob['recovered']) ?> / <?= short_money($ob['rec_target']) ?> · <?= $p ?> %</span></div><div class="bar"><i style="width:<?= min(100, $p) ?>%"></i></div></div>
      <?php endif ?>
      <div class="field"><div class="kv small" style="padding:0"><span>Qualité (reprise ≤ <?= $ob['rework_max'] ?> %)</span><span class="mono <?= $ob['rework'] > $ob['rework_max'] ? 'orange' : 'green' ?>"><?= str_replace('.', ',', (string)$ob['rework']) ?> %</span></div><div class="bar"><i class="<?= $ob['rework'] > $ob['rework_max'] ? 'orange' : '' ?>" style="width:<?= min(100, (int)round($ob['rework'] * 100 / max(1, $ob['rework_max'] * 2))) ?>%"></i></div></div>
    </div>
  </div>
</div>
