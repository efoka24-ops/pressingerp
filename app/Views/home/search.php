<div class="head"><h1>Recherche « <?= e($q) ?> »</h1></div>
<?php if (!$clients && !$orders && !$garments && !$invoices && !$quotes): ?>
  <div class="card empty">Aucun résultat. Essayez un numéro de téléphone, un nom, un n° de commande (PR-…), de pièce ou de facture (FA-…).</div>
<?php endif ?>
<?php if ($clients): ?>
<div class="card">
  <div class="card-h"><h2>Clients</h2><span class="small muted"><?= count($clients) ?></span></div>
  <table class="t"><tbody>
    <?php foreach ($clients as $c): ?>
      <tr><td><a class="row-link" href="/clients/<?= $c['id'] ?>"><?= e($c['name']) ?></a><?= client_tags($c) ?></td><td class="mono muted"><?= e($c['code']) ?></td><td class="mono"><?= e(App\Services\ClientService::formatPhone($c['phone'])) ?></td></tr>
    <?php endforeach ?>
  </tbody></table>
</div>
<?php endif ?>
<?php if ($orders): ?>
<div class="card">
  <div class="card-h"><h2>Commandes</h2></div>
  <table class="t"><tbody>
    <?php foreach ($orders as $o): $s = App\Domain\OrderStatus::from($o['status']); ?>
      <tr><td class="mono"><a class="row-link" href="/commandes/<?= $o['id'] ?>"><?= e($o['number']) ?></a></td><td><?= e($o['client']) ?></td><td><?= dt($o['created_at'], 'd/m/Y') ?></td><td class="num"><?= money($o['total']) ?></td><td class="right"><?= e($s->label()) ?></td></tr>
    <?php endforeach ?>
  </tbody></table>
</div>
<?php endif ?>
<?php if ($garments): ?>
<div class="card">
  <div class="card-h"><h2>Pièces</h2></div>
  <table class="t"><tbody>
    <?php foreach ($garments as $g): $step = App\Domain\Step::tryFrom($g['step']); ?>
      <tr><td class="mono"><a class="row-link" href="<?= can('production') ? '/scan?code=' . urlencode($g['code']) : '/tracabilite?q=' . urlencode($g['code']) ?>"><?= e($g['code']) ?></a></td><td><?= e($g['label']) ?></td><td class="mono muted"><?= e($g['number']) ?></td><td class="right"><?= e($step ? $step->label() : $g['step']) ?></td></tr>
    <?php endforeach ?>
  </tbody></table>
</div>
<?php endif ?>
<?php if ($invoices): ?>
<div class="card">
  <div class="card-h"><h2>Factures et avoirs</h2></div>
  <table class="t"><tbody>
    <?php foreach ($invoices as $i): ?>
      <tr><td class="mono"><a class="row-link" href="/commercial/factures/<?= (int)$i['id'] ?>"><?= e($i['number']) ?></a><?= $i['kind'] === 'avoir' ? ' <span class="badge">Avoir</span>' : '' ?></td><td><?= e($i['client']) ?></td><td><?= dt($i['created_at'], 'd/m/Y') ?></td><td class="num"><?= money($i['total']) ?></td><td class="right"><?= e(['payee' => 'Payée', 'partielle' => 'Partielle', 'emise' => 'Émise'][$i['status']] ?? $i['status']) ?></td></tr>
    <?php endforeach ?>
  </tbody></table>
</div>
<?php endif ?>
<?php if ($quotes): ?>
<div class="card">
  <div class="card-h"><h2>Devis</h2></div>
  <table class="t"><tbody>
    <?php foreach ($quotes as $qt): ?>
      <tr><td class="mono"><a class="row-link" href="/commercial/devis/<?= (int)$qt['id'] ?>"><?= e($qt['number']) ?></a></td><td><?= e($qt['client']) ?></td><td><?= dt($qt['created_at'], 'd/m/Y') ?></td><td class="num"><?= money($qt['total']) ?></td><td class="right"><?= e(App\Services\QuoteService::STATUS[$qt['status']] ?? $qt['status']) ?></td></tr>
    <?php endforeach ?>
  </tbody></table>
</div>
<?php endif ?>
