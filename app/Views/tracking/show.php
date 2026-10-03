<?php
use App\Domain\Step;
$first = explode(' ', trim($o['client']))[0];
$labels = ['Reçue', 'Traitement', 'Contrôle', 'Prête'];
$headline = match ($stage) {
    4 => $o['status'] === 'livre' ? 'votre commande a été livrée. Merci !' : 'votre commande a été retirée. Merci !',
    3 => 'votre commande est prête' . ($o['delivery_address'] ? ', livraison en préparation.' : ', vous pouvez passer la retirer.'),
    default => 'votre commande est en traitement. Prête <b>' . e(fdate($o['promised_at'])) . '</b>.',
};
?>
<div class="small mono muted"><?= e($o['agency']) ?> · <?= e($o['number']) ?></div>
<div>
  <h1 style="font-size:24px">Bonjour <?= e($first) ?>,</h1>
  <p style="margin:6px 0 0;color:var(--ink2);font-size:15px"><?= $headline ?></p>
</div>

<div class="progress">
  <?php foreach ($labels as $i => $l): ?>
    <div class="<?= $i < $stage || $stage === 4 ? 'done' : ($i === $stage ? 'cur' : '') ?>"><?= e($l) ?></div>
  <?php endforeach ?>
</div>

<div class="card pad">
  <?php foreach ($garments as $g): $s = Step::from($g['step']); ?>
    <div class="kv"><span style="color:var(--ink)"><?= e($g['label']) ?></span><span class="<?= $s->index() >= Step::Emballage->index() ? 'accent' : 'muted' ?>"><?= e($s->index() >= Step::Emballage->index() ? ($s === Step::Retire ? 'Remis' : 'Prêt') : $s->publicLabel()) ?></span></div>
  <?php endforeach ?>
</div>

<?php if (!in_array($o['status'], ['retire', 'livre', 'annule'], true)): ?>
  <div class="card pad" style="display:flex;justify-content:space-between;align-items:center">
    <div><div class="small muted">Reste à payer</div><div class="mono strong" style="font-size:20px"><?= money($balance) ?> <small>FCFA</small></div></div>
    <?php if ($o['paid'] > 0): ?><div class="right small muted">Déjà réglé<br><span class="mono" style="color:var(--ink)"><?= money($o['paid']) ?></span></div><?php endif ?>
  </div>

  <?php if ($balance > 0 && $checkout): ?>
    <a class="btn primary lg block" href="<?= e($checkout . (str_contains($checkout, '?') ? '&' : '?') . http_build_query(['ref' => $o['number'], 'amount' => $balance])) ?>">Payer par Mobile Money</a>
  <?php endif ?>

  <?php if (!$o['delivery_address']): ?>
    <details class="card pad">
      <summary class="strong" style="cursor:pointer">Me faire livrer · <?= money($fee, true) ?></summary>
      <form method="post" action="/suivi/<?= e($o['tracking_token']) ?>/livraison" class="form" style="margin-top:12px">
        <?= csrf_field() ?>
        <div class="field"><label>Adresse de livraison</label><input class="input lg" name="address" required placeholder="Quartier, rue, repère"></div>
        <button class="btn dark lg block">Confirmer la livraison</button>
      </form>
    </details>
  <?php else: ?>
    <div class="note">Livraison prévue : <?= e($o['delivery_address']) ?></div>
  <?php endif ?>
<?php endif ?>

<?php if ($o['agency_phone']): ?>
  <div class="center small muted">Une question ? <a href="https://wa.me/<?= e(preg_replace('/\D/', '', $o['agency_phone'])) ?>">WhatsApp l'agence</a> · <a href="tel:<?= e($o['agency_phone']) ?>">Appeler</a></div>
<?php endif ?>
