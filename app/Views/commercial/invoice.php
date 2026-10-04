<?php use App\Domain\PaymentMethod; $balance = (int)$i['total'] - (int)$i['paid']; ?>
<div class="head no-print">
  <a class="small" href="/commercial/factures">← Factures</a>
  <div class="actions"><button class="btn" onclick="window.print()">Imprimer / PDF</button></div>
</div>

<div class="split">
  <div class="card pad form">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px">
      <div><div class="brand" style="color:var(--ink);padding:0"><div class="logo">P</div>Pressing</div><div class="small muted" style="margin-top:8px">Atelier central · Douala</div></div>
      <div class="right"><h1 class="mono"><?= e($i['number']) ?></h1><div class="small muted">émise le <?= dt($i['created_at'], 'd/m/Y') ?> · échéance <?= dt($i['due_date'], 'd/m/Y') ?></div></div>
    </div>
    <div class="note"><b><?= e($i['client']) ?></b><br><?= e($i['address'] ?? '') ?><?= $i['email'] ? '<br>' . e($i['email']) : '' ?></div>
    <span class="small muted">Prestations de <?= e(month_name(substr($i['period_start'], 0, 7))) ?> (<?= dt($i['period_start'], 'd/m') ?> au <?= dt($i['period_end'], 'd/m/Y') ?>)</span>
    <table class="t">
      <thead><tr><th>Date</th><th>Bon</th><th class="num">Pièces</th><th class="num">Montant HT</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr><td><?= dt($o['created_at'], 'd/m/Y') ?></td><td class="mono"><a href="/commandes/<?= $o['id'] ?>"><?= e($o['number']) ?></a></td><td class="num"><?= $o['pcs'] ?></td><td class="num"><?= money($o['total']) ?></td></tr>
      <?php endforeach ?>
      <tr class="strong"><td colspan="2">Total</td><td class="num"><?= array_sum(array_column($orders, 'pcs')) ?></td><td class="num"><?= money($i['total']) ?> FCFA</td></tr>
      </tbody>
    </table>
  </div>

  <div class="stack no-print">
    <div class="card pad">
      <div class="kv"><span>Montant</span><span class="mono"><?= money($i['total']) ?></span></div>
      <?php foreach ($payments as $p): ?><div class="kv small"><span><?= dt($p['created_at'], 'd/m/Y') ?> · <?= e(PaymentMethod::from($p['method'])->label()) ?> <?= e($p['reference'] ?? '') ?></span><span class="mono green">−<?= money($p['amount']) ?></span></div><?php endforeach ?>
      <div class="kv total"><span>Solde</span><span class="mono <?= $balance ? 'orange' : 'green' ?>"><?= money($balance) ?></span></div>
    </div>
    <?php if ($balance > 0): ?>
    <form method="post" action="/commercial/factures/<?= $i['id'] ?>/paiement" class="card pad form">
      <?= csrf_field() ?>
      <h2>Enregistrer un règlement</h2>
      <div class="row">
        <div class="field"><label>Montant</label><input class="input mono" name="amount" type="number" min="1" max="<?= $balance ?>" value="<?= $balance ?>" required></div>
        <div class="field"><label>Mode</label><select class="input" name="method"><?php foreach ($methods as $m): ?><option value="<?= $m->value ?>"><?= e($m->label()) ?></option><?php endforeach ?></select></div>
      </div>
      <div class="field"><label>Référence (virement, chèque, transaction)</label><input class="input mono" name="reference"></div>
      <button class="btn primary">Enregistrer</button>
    </form>
    <?php endif ?>
  </div>
</div>
