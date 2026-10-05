<?php $client = $q['client']; ?>
<div class="head no-print"><a class="small" href="/commercial/devis">← Devis</a><div class="actions"><button class="btn" onclick="window.print()">Imprimer / PDF</button></div></div>
<div class="split">
  <div class="card pad form">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px">
      <div><div class="brand" style="color:var(--ink);padding:0"><div class="logo">P</div><?= e((string)App\Services\SettingsService::get('company.name', 'Pressing')) ?></div><div class="small muted"><?= e($q['agency']) ?></div></div>
      <div class="right"><h1 class="mono">Devis <?= e($q['number']) ?></h1><div class="small muted">émis le <?= dt($q['created_at'], 'd/m/Y') ?> · valable jusqu'au <?= dt($q['valid_until'], 'd/m/Y') ?></div></div>
    </div>
    <div class="note"><b><?= e($client) ?></b></div>
    <table class="t">
      <thead><tr><th>Désignation</th><th class="num">Qté</th><th class="num">Prix unitaire</th><th class="num">Montant TTC</th></tr></thead>
      <tbody><?php foreach ($lines as $l): ?><tr><td><?= e($l['label']) ?></td><td class="num"><?= e(rtrim(rtrim(number_format((float)$l['qty'], 2, ',', ''), '0'), ',')) ?></td><td class="num"><?= money($l['unit_price']) ?></td><td class="num"><?= money($l['line_total']) ?></td></tr><?php endforeach ?>
        <?php if ($q['surcharge']): ?><tr><td colspan="3">Majoration niveau de service</td><td class="num"><?= money($q['surcharge']) ?></td></tr><?php endif ?>
        <?php if ($q['discount']): ?><tr class="green"><td colspan="3">Remise</td><td class="num">−<?= money($q['discount']) ?></td></tr><?php endif ?>
        <tr class="strong"><td colspan="3">Total TTC (TVA incluse)</td><td class="num"><?= money($q['total']) ?> FCFA</td></tr></tbody>
    </table>
    <?php if ($q['notes']): ?><div class="note"><?= e($q['notes']) ?></div><?php endif ?>
    <div class="small muted">Estimation au tarif en vigueur à la date d'émission ; le prix définitif est celui du jour du dépôt.</div>
  </div>
  <div class="stack no-print">
    <div class="card pad"><div class="kv"><span>Statut</span><span class="badge"><?= e(App\Services\QuoteService::STATUS[$q['status']] ?? $q['status']) ?></span></div>
      <?php if ($q['decided_note']): ?><div class="small muted"><?= e($q['decided_note']) ?></div><?php endif ?></div>
    <?php if ($q['status'] === 'envoye'): ?>
    <form method="post" action="/commercial/devis/<?= (int)$q['id'] ?>/decision" class="card pad form"><?= csrf_field() ?>
      <h2>Réponse du client</h2>
      <div class="field"><label>Raison (obligatoire en cas de refus)</label><input class="input" name="note"></div>
      <div class="row"><button class="btn primary" name="decision" value="accepte">Accepté</button><button class="btn" name="decision" value="refuse">Refusé</button></div>
    </form>
    <?php endif ?>
  </div>
</div>
