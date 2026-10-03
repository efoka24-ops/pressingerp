<?php use App\Services\ClientService; ?>
<div class="head">
  <span class="mono small muted">01</span><h1>Clients</h1>
  <span class="small muted"><?= money($totals['n']) ?> clients · <?= (int)$totals['pros'] ?> pros · <?= (int)$totals['vip'] ?> VIP</span>
  <div class="actions"><a class="btn dark" href="/clients/nouveau">+ Nouveau client</a></div>
</div>

<form class="row" method="get">
  <div class="field" style="max-width:380px"><input class="input" name="q" value="<?= e($q) ?>" placeholder="Nom, téléphone, n° client…"></div>
  <input type="hidden" name="f" value="<?= e($f) ?>">
  <button class="btn">Rechercher</button>
  <div class="tabs" style="margin-left:auto">
    <?php foreach ($filters as $k => $label): ?><a class="<?= $f === $k ? 'on' : '' ?>" href="/clients?<?= http_build_query(array_filter(['f' => $k, 'q' => $q])) ?>"><?= e($label) ?></a><?php endforeach ?>
  </div>
</form>

<div class="card scroll">
  <?php if (!$clients): ?><div class="empty">Aucun client.</div><?php else: ?>
  <table class="t">
    <thead><tr><th>Client</th><th>N°</th><th>Téléphone</th><th class="num">Commandes</th><th class="num">CA cumulé</th><th>Dernière visite</th><th class="num">Points</th><th class="num">Solde dû</th></tr></thead>
    <tbody>
    <?php foreach ($clients as $c): $idle = $c['last_order'] ? (int)((time() - strtotime($c['last_order'])) / 86400) : null; ?>
      <tr>
        <td><a class="row-link" href="/clients/<?= $c['id'] ?>"><?= e($c['name']) ?></a><?= client_tags($c) ?></td>
        <td class="mono muted"><?= e($c['code']) ?></td>
        <td class="mono"><?= e(ClientService::formatPhone($c['phone'])) ?></td>
        <td class="num"><?= $c['orders_count'] ?></td>
        <td class="num"><?= money($c['revenue']) ?></td>
        <td class="<?= $idle !== null && $idle > 60 ? 'red' : '' ?>"><?= $c['last_order'] ? dt($c['last_order'], 'd/m/Y') . ($idle > 60 ? " · $idle j" : '') : '<span class="muted">jamais</span>' ?></td>
        <td class="num"><?= money($c['loyalty_points']) ?></td>
        <td class="num <?= $c['balance'] > 0 ? 'orange' : 'muted' ?>"><?= $c['balance'] > 0 ? money($c['balance']) : '0' ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
