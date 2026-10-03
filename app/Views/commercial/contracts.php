<?= partial('commercial/_nav', ['actions' => $unbilled ? '<form method="post" action="/commercial/factures/generer" class="inline">' . csrf_field() . '<input type="hidden" name="month" value="' . e($lastMonth) . '"><button class="btn dark">Facturer ' . e(month_name($lastMonth)) . ' (' . money($unbilled) . ')</button></form>' : '']) ?>

<div class="grid g4">
  <div class="card kpi"><span class="l">Contrats actifs</span><span class="v"><?= count($contracts) ?></span></div>
  <div class="card kpi"><span class="l">CA récurrent (mois dernier)</span><span class="v"><?= short_money($recurring) ?></span></div>
  <div class="card kpi"><span class="l">À renouveler &lt; 60 j</span><span class="v <?= $renewals ? 'orange' : '' ?>"><?= $renewals ?></span></div>
  <div class="card kpi"><span class="l">Encours pros</span><span class="v"><?= short_money(array_sum(array_column($contracts, 'outstanding'))) ?></span></div>
</div>

<div class="card scroll">
  <?php if (!$contracts): ?><div class="empty">Aucun contrat actif.</div><?php else: ?>
  <table class="t">
    <thead><tr><th>Client</th><th>Secteur</th><th>Tarif</th><th>Collecte</th><th class="num">Pièces / 30 j</th><th class="num">CA mois dernier</th><th style="width:160px">Encours / plafond</th><th>Échéance</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($contracts as $k): $soon = strtotime($k['end_date']) < strtotime('+60 days'); $use = $k['credit_limit'] ? pct($k['outstanding'], $k['credit_limit']) : 0; ?>
      <tr>
        <td><a class="row-link" href="/clients/<?= $k['client_id'] ?>"><?= e($k['client']) ?></a></td>
        <td><?= e($k['sector'] ?? '—') ?></td>
        <td><?= e($k['tariff_label'] ?? '—') ?> · −<?= (float)$k['discount_pct'] ?> %</td>
        <td><?= e($k['pickup_schedule'] ?? '—') ?></td>
        <td class="num"><?= money($k['volume']) ?></td>
        <td class="num"><?= money($k['revenue_last']) ?></td>
        <td><?php if ($k['credit_limit']): ?><div style="display:flex;align-items:center;gap:8px"><div class="bar" style="flex:1"><i class="<?= $use > 100 ? 'red' : ($use > 70 ? 'orange' : '') ?>" style="width:<?= min(100, $use) ?>%"></i></div><span class="mono small <?= $use > 100 ? 'red' : 'muted' ?>"><?= $use ?> %</span></div><?php else: ?><span class="muted">—</span><?php endif ?></td>
        <td class="<?= $soon ? 'orange' : '' ?>"><?= dt($k['end_date'], 'd/m/Y') ?></td>
        <td><form method="post" action="/commercial/contrats/<?= $k['id'] ?>/resilier" data-confirm="Résilier ce contrat ?"><?= csrf_field() ?><button class="btn sm">Résilier</button></form></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</div>

<?php if ($pros): ?>
<form method="post" action="/commercial/contrats" class="card pad form" style="max-width:900px">
  <?= csrf_field() ?>
  <h2>Nouveau contrat</h2>
  <div class="row">
    <div class="field"><label>Client pro *</label><select class="input" name="client_id" required><?php foreach ($pros as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Secteur</label><input class="input" name="sector" placeholder="Hôtellerie, santé…"></div>
    <div class="field"><label>Grille tarifaire</label><input class="input" name="tariff_label" placeholder="Grille H-2"></div>
  </div>
  <div class="row">
    <div class="field"><label>Remise %</label><input class="input mono" name="discount_pct" type="number" min="0" max="60" step="0.5" value="10"></div>
    <div class="field"><label>Collecte</label><input class="input" name="pickup_schedule" placeholder="Lun · Mer · Ven 9 h"></div>
    <div class="field"><label>Début *</label><input class="input" type="date" name="start_date" value="<?= date('Y-m-d') ?>" required></div>
    <div class="field"><label>Fin *</label><input class="input" type="date" name="end_date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required></div>
  </div>
  <div class="row">
    <div class="field"><label>Plafond d'encours</label><input class="input mono" name="credit_limit" type="number" min="0" step="50000"></div>
    <div class="field"><label>Délai de paiement (j)</label><input class="input mono" name="payment_terms_days" type="number" min="0" value="30"></div>
    <button class="btn primary">Créer le contrat</button>
  </div>
</form>
<?php endif ?>
