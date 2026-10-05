<?= partial('commercial/_nav', ['actions' => '<a class="btn primary" href="/commercial/devis/nouveau">+ Nouveau devis</a>']) ?>
<div class="card scroll">
  <?php if (!$quotes): ?><div class="empty">Aucun devis.</div><?php else: ?>
  <table class="t">
    <thead><tr><th>Devis</th><th>Client</th><th>Émis le</th><th>Valable jusqu'au</th><th class="num">Total TTC</th><th class="right">Statut</th></tr></thead>
    <tbody>
    <?php foreach ($quotes as $q): ?>
      <tr><td class="mono"><a class="row-link" href="/commercial/devis/<?= (int)$q['id'] ?>"><?= e($q['number']) ?></a></td><td><?= e($q['client']) ?></td><td><?= dt($q['created_at'], 'd/m/Y') ?></td><td><?= dt($q['valid_until'], 'd/m/Y') ?></td>
        <td class="num"><?= money($q['total']) ?></td>
        <td class="right"><span class="badge <?= ['accepte' => 'green', 'refuse' => 'red', 'expire' => '', 'envoye' => 'orange'][$q['status']] ?? '' ?>"><?= e(App\Services\QuoteService::STATUS[$q['status']] ?? $q['status']) ?></span></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
