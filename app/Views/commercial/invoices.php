<?= partial('commercial/_nav', ['actions' => '<form method="post" action="/commercial/factures/generer" class="row">' . csrf_field() . '<input class="input" type="month" name="month" value="' . e($lastMonth) . '" max="' . date('Y-m') . '" style="width:auto"><button class="btn dark">Générer les factures du mois</button></form>']) ?>

<div class="tabs">
  <?php foreach (['' => 'Toutes', 'emise' => 'Émises', 'partielle' => 'Partiellement réglées', 'payee' => 'Payées'] as $k => $l): ?>
    <a class="<?= $status === $k ? 'on' : '' ?>" href="/commercial/factures<?= $k ? '?statut=' . $k : '' ?>"><?= e($l) ?></a>
  <?php endforeach ?>
</div>

<div class="card scroll">
  <?php if (!$invoices): ?><div class="empty">Aucune facture.</div><?php else: ?>
  <table class="t">
    <thead><tr><th>Facture</th><th>Client</th><th>Période</th><th class="num">Montant</th><th class="num">Réglé</th><th class="num">Solde</th><th>Échéance</th><th class="right">Statut</th></tr></thead>
    <tbody>
    <?php foreach ($invoices as $i): $late = $i['status'] !== 'payee' && $i['due_date'] < date('Y-m-d'); ?>
      <tr class="<?= $late ? 'alert' : '' ?>">
        <td class="mono"><a class="row-link" href="/commercial/factures/<?= $i['id'] ?>"><?= e($i['number']) ?></a></td>
        <td><?= e($i['client']) ?></td>
        <td><?= e(month_name(substr($i['period_start'], 0, 7))) ?></td>
        <td class="num"><?= money($i['total']) ?></td>
        <td class="num"><?= money($i['paid']) ?></td>
        <td class="num strong"><?= money($i['total'] - $i['paid']) ?></td>
        <td class="<?= $late ? 'red' : '' ?>"><?= dt($i['due_date'], 'd/m/Y') ?><?= $late ? ' · ' . (int)((time() - strtotime($i['due_date'])) / 86400) . ' j' : '' ?></td>
        <td class="right"><span class="badge <?= ['payee' => 'green', 'partielle' => 'orange', 'emise' => ''][$i['status']] ?? '' ?>"><?= e(['payee' => 'Payée', 'partielle' => 'Partielle', 'emise' => 'Émise'][$i['status']] ?? $i['status']) ?></span></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>
