<?php
use App\Domain\PaymentMethod;
$isCredit = $i['kind'] === 'avoir';
$balance = $isCredit ? 0 : (int)$i['total'] - (int)$i['paid'];
$manager = App\Core\Auth::isManager();
?>
<div class="head no-print">
  <a class="small" href="/commercial/factures">← Factures</a>
  <div class="actions"><button class="btn" onclick="window.print()">Imprimer / PDF</button></div>
</div>

<div class="split">
  <div class="card pad form">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px">
      <div>
        <div class="brand" style="color:var(--ink);padding:0"><div class="logo">P</div><?= e($i['seller_name'] ?: 'Pressing') ?></div>
        <div class="small muted" style="margin-top:8px"><?= e($agency) ?><?= $i['seller_niu'] ? '<br>NIU : ' . e($i['seller_niu']) : '<br><span class="red">NIU de l\'entreprise non renseigné</span>' ?></div>
      </div>
      <div class="right"><h1 class="mono"><?= $isCredit ? 'Avoir ' : '' ?><?= e($i['number']) ?></h1>
        <div class="small muted">émis le <?= dt($i['created_at'], 'd/m/Y') ?><?= $isCredit ? '' : ' · échéance ' . dt($i['due_date'], 'd/m/Y') ?></div></div>
    </div>
    <div class="note"><b><?= e($i['client']) ?></b><?= $i['client_niu'] ? '<br>NIU : ' . e($i['client_niu']) : '' ?><br><?= e($i['address'] ?? '') ?><?= $i['email'] ? '<br>' . e($i['email']) : '' ?></div>
    <?php if ($isCredit): ?>
      <div class="small">Avoir sur la facture <a href="/commercial/factures/<?= (int)$i['ref_invoice_id'] ?>"><?= e($refNumber) ?></a>. Motif : <?= e($i['reason']) ?></div>
    <?php else: ?>
      <span class="small muted">Prestations de <?= e(month_name(substr($i['period_start'], 0, 7))) ?> (<?= dt($i['period_start'], 'd/m') ?> au <?= dt($i['period_end'], 'd/m/Y') ?>)</span>
      <table class="t">
        <thead><tr><th>Date</th><th>Bon</th><th class="num">Pièces</th><th class="num">Montant TTC</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr><td><?= dt($o['created_at'], 'd/m/Y') ?></td><td class="mono"><a href="/commandes/<?= $o['id'] ?>"><?= e($o['number']) ?></a></td><td class="num"><?= $o['pcs'] ?></td><td class="num"><?= money($o['total'] - $o['paid']) ?></td></tr>
        <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
    <table class="t" style="max-width:360px;margin-left:auto">
      <tbody>
        <tr><td>Total HT</td><td class="num"><?= money($i['subtotal']) ?> FCFA</td></tr>
        <tr><td>TVA <?= e(rtrim(rtrim(number_format((float)$i['vat_rate'], 2, ',', ''), '0'), ',')) ?> %</td><td class="num"><?= money($i['vat_amount']) ?> FCFA</td></tr>
        <tr class="strong"><td>Total TTC</td><td class="num"><?= money($i['total']) ?> FCFA</td></tr>
        <?php if ((int)$i['credited'] > 0): ?><tr class="small muted"><td>dont avoirs déjà déduits</td><td class="num">−<?= money($i['credited']) ?></td></tr><?php endif ?>
      </tbody>
    </table>
  </div>

  <div class="stack no-print">
    <?php if (!$isCredit): ?>
    <div class="card pad">
      <div class="kv"><span>Montant TTC</span><span class="mono"><?= money($i['total']) ?></span></div>
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
    <?php if (can('commercial', 'validate')): ?>
    <form method="post" action="/commercial/factures/<?= $i['id'] ?>/avoir" class="card pad form">
      <?= csrf_field() ?>
      <h2>Émettre un avoir</h2>
      <div class="small muted">Réduit cette facture sans la modifier : un document d'avoir est créé et numéroté. Plafond : le reste dû.</div>
      <div class="field"><label>Montant TTC de l'avoir</label><input class="input mono" name="amount" type="number" min="1" max="<?= $balance ?>" required></div>
      <div class="field"><label>Motif</label><input class="input" name="reason" required minlength="8" placeholder="Erreur de facturation, geste commercial…"></div>
      <?php if (!$manager): ?><div class="row"><div class="field"><label>Responsable : identifiant</label><input class="input" name="auth_login" autocomplete="off" required></div><div class="field"><label>Mot de passe</label><input class="input" type="password" name="auth_password" autocomplete="off" required></div></div><?php endif ?>
      <button class="btn">Émettre l'avoir</button>
    </form>
    <?php endif ?>
    <?php endif ?>
    <?php if ($credits): ?>
    <div class="card pad"><h2>Avoirs émis</h2>
      <?php foreach ($credits as $c): ?><div class="kv small"><span><a href="/commercial/factures/<?= (int)$c['id'] ?>"><?= e($c['number']) ?></a> · <?= e($c['reason']) ?></span><span class="mono">−<?= money($c['total']) ?></span></div><?php endforeach ?>
    </div>
    <?php endif ?>
    <?php endif ?>
  </div>
</div>
