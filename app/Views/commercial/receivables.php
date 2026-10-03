<?= partial('commercial/_nav', []) ?>

<div class="card pad form">
  <div class="card-h"><span class="muted">Encours total</span><span class="mono strong" style="font-size:24px"><?= money($total) ?> <small>FCFA</small></span></div>
  <?php $colors = ['b0' => '#1F5F5B', 'b30' => '#8DB8B2', 'b60' => '#E8B868', 'b90' => '#D97B5E', 'b90p' => '#B3362B']; ?>
  <div class="stack-bar"><?php foreach ($buckets as $k => $l): if ($totals[$k] > 0): ?><div style="flex:<?= $totals[$k] ?>;background:<?= $colors[$k] ?>" title="<?= e($l) ?>"></div><?php endif; endforeach ?></div>
  <div class="grid" style="grid-template-columns:repeat(5,minmax(0,1fr))">
    <?php foreach ($buckets as $k => $l): ?><div><div class="small <?= $k === 'b90p' ? 'red' : 'muted' ?>"><?= e($l) ?></div><div class="mono strong <?= $k === 'b90p' && $totals[$k] ? 'red' : '' ?>"><?= money($totals[$k]) ?></div></div><?php endforeach ?>
  </div>
</div>

<div class="card scroll">
  <div class="card-h"><h2>Balance âgée par client</h2><span class="small <?= $dueToday ? 'red' : 'muted' ?>"><?= $dueToday ?> relance(s) à faire</span></div>
  <?php if (!$rows): ?><div class="empty">Aucune créance en cours.</div><?php else: ?>
  <table class="t">
    <thead><tr><th>Client</th><?php foreach ($buckets as $l): ?><th class="num"><?= e($l) ?></th><?php endforeach ?><th class="num">Solde</th><th style="width:150px">Plafond utilisé</th><th class="num">Retard</th><th>Dernière relance</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $use = $r['credit_limit'] ? pct($r['balance'], $r['credit_limit']) : 0; ?>
      <tr class="<?= $use > 100 || $r['days_late'] > 60 ? 'alert' : '' ?>">
        <td><a class="row-link" href="/clients/<?= $r['id'] ?>"><?= e($r['name']) ?></a></td>
        <?php foreach (array_keys($buckets) as $k): ?><td class="num <?= $r[$k] && in_array($k, ['b90', 'b90p'], true) ? 'red' : ($r[$k] ? '' : 'muted') ?>"><?= $r[$k] ? money($r[$k]) : '—' ?></td><?php endforeach ?>
        <td class="num strong"><?= money($r['balance']) ?></td>
        <td><?php if ($r['credit_limit']): ?><div style="display:flex;align-items:center;gap:8px"><div class="bar" style="flex:1"><i class="<?= $use > 100 ? 'red' : ($use > 70 ? 'orange' : '') ?>" style="width:<?= min(100, $use) ?>%"></i></div><span class="mono small <?= $use > 100 ? 'red' : 'muted' ?>"><?= $use ?> %</span></div><?php else: ?><span class="muted">—</span><?php endif ?></td>
        <td class="num <?= $r['days_late'] > 60 ? 'red strong' : ($r['days_late'] ? 'orange' : 'muted') ?>"><?= $r['days_late'] ?> j</td>
        <td class="<?= $r['due_today'] ? 'red strong' : 'muted' ?>"><?= $r['due_today'] ? 'À relancer aujourd\'hui' : ($r['last_reminder'] ? e(ucfirst($r['last_reminder']['channel'])) . ' · ' . dt($r['last_reminder']['created_at'], 'd/m') : '—') ?></td>
        <td>
          <details><summary class="small accent" style="cursor:pointer">Relancer</summary>
            <form method="post" action="/recouvrement/relance" class="form" style="min-width:240px;padding-top:8px">
              <?= csrf_field() ?><input type="hidden" name="client_id" value="<?= $r['id'] ?>">
              <select class="input" name="channel"><option value="whatsapp">WhatsApp</option><option value="sms">SMS</option><option value="email">E-mail</option><option value="appel">Appel</option><option value="visite">Visite</option></select>
              <input class="input" name="note" placeholder="Promesse de paiement, interlocuteur…">
              <button class="btn sm primary">Enregistrer la relance</button>
            </form>
          </details>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
