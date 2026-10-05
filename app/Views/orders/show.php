<?php
use App\Core\Auth;
use App\Domain\GarmentStatus;
use App\Domain\OrderStatus;
use App\Domain\PaymentMethod;
use App\Domain\ServiceLevel;
use App\Domain\Step;
use App\Services\ClientService;

$s = OrderStatus::from($o['status']);
$r = risk($o['promised_at'], $o['status']);
$balance = $o['on_account'] ? 0 : (int)$o['total'] - (int)$o['paid'];
?>
<div class="head">
  <a class="small" href="/commandes">← Commandes</a>
  <h1 class="mono"><?= e($o['number']) ?></h1>
  <span class="badge <?= $r === 'red' ? 'red' : $s->tone() ?>"><?= $r === 'red' ? 'En retard' : e($s->label()) ?></span>
  <span class="small muted"><?= e($o['agency']) ?> · reçue <?= dt($o['created_at']) ?> par <?= e($o['user'] ?? '—') ?></span>
  <div class="actions">
    <a class="btn" href="/commandes/<?= $o['id'] ?>/ticket" target="_blank">Ticket de dépôt</a>
    <a class="btn" href="/commandes/<?= $o['id'] ?>/etiquettes" target="_blank" id="print-labels">Imprimer les étiquettes</a>
    <a class="btn sm" href="/commandes/<?= $o['id'] ?>/etiquettes?manuel=1" target="_blank" title="Imprimante en panne : liste des codes à écrire à la main">Étiquetage manuel</a>
    <a class="btn" href="<?= e(tracking_url($o['tracking_token'])) ?>" target="_blank" rel="noopener">Page de suivi client</a>
  </div>
</div>

<div class="split">
  <div class="stack">
    <div class="card pad" style="display:flex;align-items:center;gap:14px">
      <div class="avatar" style="width:42px;height:42px;background:var(--ink)"><?= e(initials($o['client'])) ?></div>
      <div style="flex:1"><a class="strong" href="/clients/<?= $o['client_id'] ?>"><?= e($o['client']) ?></a><?= client_tags($o) ?><div class="mono small muted"><?= e($o['client_code']) ?> · <?= e(ClientService::formatPhone($o['phone'])) ?></div></div>
      <?php if ($o['preferences']): ?><div class="note small" style="max-width:320px"><?= e($o['preferences']) ?></div><?php endif ?>
    </div>

    <div class="card scroll">
      <div class="card-h"><h2>Pièces</h2><span class="small muted"><?= count($garments) ?> pièce<?= count($garments) > 1 ? 's' : '' ?></span></div>
      <table class="t">
        <thead><tr><th>Code</th><th>Article</th><th>Détails</th><th>Étape</th><th>Depuis</th><th class="num">Prix</th></tr></thead>
        <tbody>
        <?php foreach ($garments as $g): $gs = GarmentStatus::from($g['status']); ?>
          <tr class="<?= in_array($gs, [GarmentStatus::Bloque, GarmentStatus::AReprendre], true) ? 'alert' : '' ?>">
            <td class="mono"><a href="/tracabilite?q=<?= urlencode($g['code']) ?>"><?= e($g['code']) ?></a></td>
            <td><?= e($g['label']) ?><?= (float)$g['qty'] != 1.0 ? ' <span class="muted">· ' . (float)$g['qty'] . ' m²</span>' : '' ?><?= $g['photo_path'] ? ' <a class="small" href="' . e($g['photo_path']) . '" target="_blank">photo</a>' : '' ?></td>
            <td class="small"><?= e(implode(' · ', array_filter([$g['brand'], $g['color'], $g['material']]))) ?><?php if ($g['damages']): ?><div class="orange"><?= e($g['damages']) ?></div><?php endif ?></td>
            <td><?= e(Step::from($g['step'])->label()) ?><?php if ($gs !== GarmentStatus::Termine): ?> · <span class="<?= $gs->tone() ?>"><?= e($gs->label()) ?></span><?php endif ?><?= $g['operator'] ? '<div class="small muted">' . e($g['operator']) . '</div>' : '' ?></td>
            <td class="mono small"><?= in_array($g['step'], ['pret', 'retire'], true) ? '—' : since($g['step_since']) ?></td>
            <td class="num"><?= money($g['price']) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>

    <?php if ($o['notes']): ?><div class="note" style="white-space:pre-line"><?= e($o['notes']) ?></div><?php endif ?>
  </div>

  <aside class="stack">
    <div class="card pad">
      <div class="kv"><span>Service</span><span><?= e(ServiceLevel::from($o['service_level'])->label()) ?></span></div>
      <div class="kv"><span>Promis</span><span class="mono <?= $r === 'red' ? 'red' : ($r === 'orange' ? 'orange' : '') ?>"><?= fdate($o['promised_at']) ?></span></div>
      <?php if ($o['rail']): ?><div class="kv"><span>Emplacement</span><span class="mono"><?= e($o['rail']) ?></span></div><?php endif ?>
      <?php if ($o['delivery_address']): ?><div class="kv"><span>Livraison</span><span class="right"><?= e($o['delivery_address']) ?></span></div><?php endif ?>
      <?php if ($o['picked_up_at']): ?><div class="kv"><span><?= $o['status'] === 'livre' ? 'Livrée' : 'Retirée' ?></span><span class="mono"><?= dt($o['picked_up_at']) ?></span></div><?php endif ?>
    </div>

    <div class="card pad">
      <div class="kv"><span>Sous-total</span><span class="mono"><?= money($o['subtotal']) ?></span></div>
      <?php if ($o['surcharge']): ?><div class="kv"><span>Majoration <?= e(ServiceLevel::from($o['service_level'])->label()) ?></span><span class="mono"><?= money($o['surcharge']) ?></span></div><?php endif ?>
      <?php if ($o['discount']): ?><div class="kv green"><span><?= e($o['discount_label']) ?></span><span class="mono">−<?= money($o['discount']) ?></span></div><?php endif ?>
      <?php if ($o['delivery_fee']): ?><div class="kv"><span>Livraison</span><span class="mono"><?= money($o['delivery_fee']) ?></span></div><?php endif ?>
      <div class="kv total"><span>Total</span><span class="mono" style="font-size:20px"><?= money($o['total']) ?> <small>FCFA</small></span></div>
      <?php $reversed = array_column(array_filter($payments, fn($x) => $x['kind'] === 'reversal'), 'reverses_id'); ?>
      <?php foreach ($payments as $p): $rev = $p['kind'] === 'reversal'; ?>
        <div class="kv small"><span><?= dt($p['created_at'], 'd/m H:i') ?> · <?= e(PaymentMethod::from($p['method'])->label()) ?><?= $p['reference'] ? ' · ' . e($p['reference']) : '' ?><?= $p['split_group'] ? ' · mixte' : '' ?><?= $rev ? ' · <b class="red">ANNULATION</b>' : '' ?> · <a href="/paiements/<?= (int)$p['id'] ?>/recu" target="_blank"><?= e($p['receipt_no'] ?? 'reçu') ?></a></span><span class="mono <?= $rev ? 'red' : 'green' ?>"><?= $p['amount'] > 0 ? '−' : '+' ?><?= money(abs((int)$p['amount'])) ?></span></div>
      <?php endforeach ?>
      <?php if ($o['on_account']): ?>
        <div class="kv"><span>Règlement</span><span>En compte (facture mensuelle)</span></div>
      <?php else: ?>
        <div class="kv total"><span>Reste à payer</span><span class="mono <?= $balance > 0 ? 'orange' : 'green' ?>"><?= money($balance) ?></span></div>
      <?php endif ?>
    </div>

    <?php if ($o['status'] === 'pret'): ?>
      <form method="post" action="/commandes/<?= $o['id'] ?>/retrait" class="card pad form">
        <?= csrf_field() ?>
        <h2><?= $o['delivery_address'] ? 'Remise au livreur / livraison' : 'Retrait client' ?></h2>
        <?php if ($balance > 0): ?>
          <div class="field"><label>Solde de <?= money($balance, true) ?> payé par</label><select class="input" name="method" required><?php foreach ($methods as $m): ?><option value="<?= $m->value ?>"><?= e($m->label()) ?></option><?php endforeach ?></select></div>
        <?php endif ?>
        <button class="btn dark lg block"><?= $balance > 0 ? 'Encaisser ' . money($balance) . ' et remettre' : 'Remettre au client' ?></button>
      </form>
    <?php elseif ($balance > 0 && $o['status'] === 'en_atelier'): ?>
      <form method="post" action="/commandes/<?= $o['id'] ?>/paiement" class="card pad form">
        <?= csrf_field() ?>
        <h2>Encaisser</h2>
        <?php foreach ([0, 1] as $n): ?>
        <div class="row">
          <div class="field"><label><?= $n === 0 ? 'Montant' : 'Autre montant (paiement mixte)' ?></label><input class="input mono" name="lines[<?= $n ?>][amount]" type="number" min="50" max="<?= $balance ?>" step="50" <?= $n === 0 ? 'value="' . $balance . '" required' : 'placeholder="optionnel"' ?>></div>
          <div class="field"><label>Mode</label><select class="input" name="lines[<?= $n ?>][method]"><?php foreach ($methods as $m): ?><option value="<?= $m->value ?>" <?= $n === 1 && $m->value === 'orange' ? 'selected' : '' ?>><?= e($m->label()) ?></option><?php endforeach ?></select></div>
          <div class="field"><label>Réf.</label><input class="input mono" name="lines[<?= $n ?>][reference]" style="width:120px"></div>
        </div>
        <?php endforeach ?>
        <button class="btn primary block">Encaisser (un seul reçu)</button>
      </form>
    <?php endif ?>


    <?php $manager = Auth::isManager(); $authFields = $manager ? '' : '<div class="row"><div class="field"><label>Responsable : identifiant</label><input class="input" name="auth_login" autocomplete="off" required></div><div class="field"><label>Mot de passe</label><input class="input" type="password" name="auth_password" autocomplete="off" required></div></div>'; ?>

    <?php if ($intents || ($balance > 0 && in_array($o['status'], ['en_atelier', 'pret'], true))): ?>
    <div class="card pad form">
      <h2>Mobile Money</h2>
      <?php if ($balance > 0 && in_array($o['status'], ['en_atelier', 'pret'], true)): ?>
      <form method="post" action="/commandes/<?= $o['id'] ?>/paiement-mobile" class="row" style="gap:8px;flex-wrap:wrap"><?= csrf_field() ?>
        <div class="field"><label>Opérateur</label><select class="input" name="method"><option value="orange">Orange Money</option><option value="mtn">MTN MoMo</option></select></div>
        <div class="field"><label>Téléphone du payeur</label><input class="input mono" name="phone" inputmode="tel" placeholder="6 70 12 34 56" required></div>
        <button class="btn primary">Demander <?= money($balance) ?> FCFA</button>
      </form>
      <?php endif ?>
      <?php foreach ($intents as $in): ?>
        <div class="kv small"><span><?= dt($in['created_at'], 'd/m H:i') ?> · <?= e(PaymentMethod::from($in['method'])->label()) ?> · <?= e($in['phone']) ?> · <?= money($in['amount']) ?></span>
          <span class="<?= $in['status'] === 'CONFIRMED' ? 'green' : ($in['status'] === 'FAILED' ? 'red' : 'orange') ?>"><?= $in['status'] === 'CONFIRMED' ? 'Confirmé' : ($in['status'] === 'FAILED' ? 'Échoué' : 'En attente') ?></span></div>
        <?php if ($in['status'] !== 'CONFIRMED'): ?>
        <details><summary class="small">Confirmer manuellement (relevé de l'opérateur)</summary>
          <form method="post" action="/paiement-mobile/<?= (int)$in['id'] ?>/confirmer" class="form"><?= csrf_field() ?>
            <div class="field"><label>Référence de la transaction chez l'opérateur</label><input class="input mono" name="operator_ref" required minlength="6"></div>
            <?= $authFields ?>
            <button class="btn sm">Confirmer le paiement</button>
          </form>
        </details>
        <?php endif ?>
      <?php endforeach ?>
    </div>
    <?php endif ?>

    <?php if (in_array($o['status'], ['en_atelier', 'pret'], true)): ?>
    <details class="card pad">
      <summary><b>Remise</b> <span class="small muted">(autorisation d'un responsable)</span></summary>
      <form method="post" action="/commandes/<?= $o['id'] ?>/remise" class="form"><?= csrf_field() ?>
        <div class="row"><div class="field"><label>Montant de la remise (FCFA)</label><input class="input mono" name="amount" type="number" min="50" step="50" required></div>
        <div class="field"><label>Motif</label><input class="input" name="reason" required minlength="8" placeholder="ex. retard de livraison, geste commercial"></div></div>
        <?= $authFields ?>
        <button class="btn sm">Accorder la remise</button>
      </form>
    </details>
    <?php endif ?>

    <?php $cancellable = array_filter($payments, fn($x) => $x['kind'] === 'payment' && !in_array($x['id'], $reversed, true) && !$x['invoice_id']); ?>
    <?php if ($cancellable && in_array($o['status'], ['en_atelier', 'pret'], true)): ?>
    <details class="card pad">
      <summary><b>Annuler un encaissement</b> <span class="small muted">(écriture inverse, autorisation d'un responsable)</span></summary>
      <form method="post" action="" class="form" onsubmit="this.action='/paiements/' + this.payment.value + '/annuler'"><?= csrf_field() ?>
        <div class="field"><label>Encaissement</label><select class="input" name="payment"><?php foreach ($cancellable as $cp): ?><option value="<?= (int)$cp['id'] ?>"><?= e($cp['receipt_no']) ?> · <?= e(PaymentMethod::from($cp['method'])->label()) ?> · <?= money($cp['amount']) ?> FCFA</option><?php endforeach ?></select></div>
        <div class="field"><label>Motif</label><input class="input" name="reason" required minlength="8"></div>
        <?= $authFields ?>
        <button class="btn ghost-danger">Annuler cet encaissement</button>
      </form>
    </details>
    <?php endif ?>
    <?php if (Auth::isManager() && in_array($o['status'], ['en_atelier', 'pret'], true) && !(int)$o['paid']): ?>
      <form method="post" action="/commandes/<?= $o['id'] ?>/annuler" class="row" data-confirm="Annuler définitivement cette commande ?">
        <?= csrf_field() ?>
        <div class="field"><input class="input" name="reason" placeholder="Motif d'annulation" required></div>
        <button class="btn ghost-danger">Annuler la commande</button>
      </form>
    <?php endif ?>
  </aside>
</div>

<?php if ($printLabels): ?>
<script>window.addEventListener('load', function () { window.open(document.getElementById('print-labels').href, '_blank'); });</script>
<?php endif ?>
