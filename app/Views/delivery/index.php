<?php
use App\Services\DeliveryService;
$tone = ['a_collecter' => 'orange', 'collecte' => '', 'en_traitement' => '', 'a_livrer' => 'orange', 'en_route' => 'blue', 'livre' => 'green', 'non_livre' => 'red'];
?>
<div class="head"><span class="mono small muted">13</span><h1><?= $driver ? 'Ma tournée' : 'Livraisons & collectes' ?></h1>
  <?php if (!$driver && App\Core\Auth::can('delivery', 'create')): ?><a class="btn primary" href="/livraisons/collecte">+ Demande de collecte</a><?php endif ?></div>

<?php if (!$driver): ?>
<div class="tabs">
  <?php foreach ($tabs as $k => $label): ?><a class="tab<?= $k === $tab ? ' on' : '' ?>" href="/livraisons?onglet=<?= $k ?>"><?= e($label) ?> <span class="count"><?= (int)$counts[$k] ?></span></a><?php endforeach ?>
</div>
<?php endif ?>

<div class="stack">
<?php foreach ($rows as $d): ?>
  <a class="card pad" href="/livraisons/<?= (int)$d['id'] ?>" style="display:block;color:inherit;text-decoration:none">
    <div class="kv"><span><b><?= $d['kind'] === 'collect' ? 'Collecte' : 'Livraison' ?></b> · <?= e($d['number'] ?? 'sans commande') ?> · <?= e($d['client']) ?></span>
      <span class="badge <?= $tone[$d['status']] ?? '' ?>"><?= e(DeliveryService::STATUS[$d['status']] ?? $d['status']) ?></span></div>
    <div class="small"><?= e($d['address']) ?></div>
    <div class="small muted"><?= $d['slot_at'] ? 'Créneau : ' . dt($d['slot_at'], 'd/m H:i') : 'Sans créneau' ?> · <?= $d['driver'] ? 'Livreur : ' . e($d['driver']) : '<span class="red">Sans livreur</span>' ?><?= (int)$d['attempts'] > 0 ? ' · tentative ' . (int)$d['attempts'] : '' ?><?php if ($d['number'] && !(int)$d['on_account'] && (int)$d['total'] > (int)$d['paid']): ?> · à encaisser <?= money((int)$d['total'] - (int)$d['paid'], true) ?><?php endif ?></div>
  </a>
<?php endforeach ?>
<?php if (!$rows): ?><div class="card pad muted"><?= $driver ? 'Rien à livrer ni à collecter pour le moment.' : 'Rien dans cet onglet.' ?></div><?php endif ?>
</div>
