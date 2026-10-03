<div class="head"><h1>Recherche « <?= e($q) ?> »</h1></div>
<?php if (!$clients && !$orders): ?>
  <div class="card empty">Aucun résultat. Essayez un numéro de téléphone, un nom, un n° de commande (PR-…) ou de pièce.</div>
<?php endif ?>
<?php if ($clients): ?>
<div class="card">
  <div class="card-h"><h2>Clients</h2><span class="small muted"><?= count($clients) ?></span></div>
  <table class="t"><tbody>
    <?php foreach ($clients as $c): ?>
      <tr><td><a class="row-link" href="/clients/<?= $c['id'] ?>"><?= e($c['name']) ?></a><?= client_tags($c) ?></td><td class="mono muted"><?= e($c['code']) ?></td><td class="mono"><?= e(App\Services\ClientService::formatPhone($c['phone'])) ?></td><td class="right"><a class="btn sm" href="/commandes/nouvelle?client=<?= $c['id'] ?>">Nouvelle commande</a></td></tr>
    <?php endforeach ?>
  </tbody></table>
</div>
<?php endif ?>
<?php if ($orders): ?>
<div class="card">
  <div class="card-h"><h2>Commandes</h2></div>
  <table class="t"><tbody>
    <?php foreach ($orders as $o): $s = App\Domain\OrderStatus::from($o['status']); ?>
      <tr><td class="mono"><a class="row-link" href="/commandes/<?= $o['id'] ?>"><?= e($o['number']) ?></a></td><td><?= e($o['client']) ?></td><td><?= dt($o['created_at'], 'd/m/Y') ?></td><td class="num"><?= money($o['total']) ?></td><td class="right"><span class="badge <?= $s->tone() ?>"><?= e($s->label()) ?></span></td></tr>
    <?php endforeach ?>
  </tbody></table>
</div>
<?php endif ?>
