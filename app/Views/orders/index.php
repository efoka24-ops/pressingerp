<?php use App\Domain\OrderStatus; use App\Domain\ServiceLevel; ?>
<div class="head">
  <span class="mono small muted">02</span><h1>Commandes</h1>
  <div class="actions"><a class="btn primary" href="/commandes/nouvelle">+ Nouvelle commande</a></div>
</div>

<div class="row" style="align-items:center">
  <div class="tabs">
    <?php foreach ($tabs as $k => $label): ?>
      <a class="<?= $tab === $k ? 'on' : '' ?> <?= $k === 'retard' && ($counts[$k] ?? 0) ? 'red' : '' ?>" href="/commandes?<?= http_build_query(array_filter(['tab' => $k, 'agence' => $ag, 'q' => $q])) ?>"><?= e($label) ?><?php if (isset($counts[$k])): ?><span class="mono"><?= $counts[$k] ?></span><?php endif ?></a>
    <?php endforeach ?>
  </div>
  <form method="get" class="row" style="margin-left:auto">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <select class="input" name="agence" data-autosubmit style="width:auto"><option value="0">Toutes agences</option><?php foreach ($agencies as $a): ?><option value="<?= $a['id'] ?>"<?= selected($ag, $a['id']) ?>><?= e($a['name']) ?></option><?php endforeach ?></select>
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="N°, client, téléphone" style="width:220px">
  </form>
</div>

<div class="card scroll">
  <?php if (!$orders): ?><div class="empty">Aucune commande dans cette vue.</div><?php else: ?>
  <table class="t">
    <thead><tr><th></th><th>Commande</th><th>Client</th><th>Agence</th><th>Service</th><th style="width:180px">Avancement</th><th>Promis</th><th class="num">Montant</th><th class="right">Paiement</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o):
      $r = risk($o['promised_at'], $o['status']);
      $done = (int)$o['done']; $pcs = max(1, (int)$o['pcs']);
      [$pl, $pt] = payment_label($o);
      $s = OrderStatus::from($o['status']); ?>
      <tr class="<?= $r === 'red' ? 'alert' : '' ?>">
        <td><?= dot($r) ?></td>
        <td class="mono"><a class="row-link" href="/commandes/<?= $o['id'] ?>"><?= e($o['number']) ?></a></td>
        <td><?= e($o['client']) ?><?= client_tags($o) ?></td>
        <td><?= e($o['agency']) ?></td>
        <td><?= e(ServiceLevel::from($o['service_level'])->label()) ?><?= $o['delivery_address'] ? ' · <span class="muted">livraison</span>' : '' ?></td>
        <td>
          <?php if (in_array($o['status'], ['en_atelier', 'pret'], true)): ?>
            <div style="display:flex;align-items:center;gap:8px"><div class="bar" style="flex:1"><i class="<?= $r === 'red' ? 'red' : ($r === 'orange' ? 'orange' : '') ?>" style="width:<?= max(4, (int)round($done * 100 / $pcs)) ?>%"></i></div><span class="mono small muted"><?= $done ?>/<?= (int)$o['pcs'] ?></span></div>
          <?php else: ?><span class="badge <?= $s->tone() ?>"><?= e($s->label()) ?></span><?php endif ?>
        </td>
        <td class="mono nowrap <?= $r === 'red' ? 'red' : ($r === 'orange' ? 'orange' : '') ?>"><?= fdate($o['promised_at']) ?></td>
        <td class="num"><?= money($o['total']) ?></td>
        <td class="right <?= $pt ?>"><?= e($pl) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
