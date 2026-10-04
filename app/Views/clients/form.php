<?php
$v = fn(string $k, mixed $d = '') => old($k, $c[$k] ?? $d);
$isPro = $v('type', 'particulier') === 'pro';
?>
<div class="head"><h1><?= e($title) ?></h1></div>
<form method="post" action="<?= $c ? '/clients/' . $c['id'] : '/clients' ?>" class="card pad form" style="max-width:820px">
  <?= csrf_field() ?>
  <?php if ($back): ?><input type="hidden" name="retour" value="<?= e($back) ?>"><?php endif ?>
  <div class="field"><span class="label">Type</span>
    <div class="seg" style="max-width:360px">
      <label><input type="radio" name="type" value="particulier"<?= checked(!$isPro) ?> onchange="document.getElementById('pro').hidden=true">Particulier</label>
      <label><input type="radio" name="type" value="pro"<?= checked($isPro) ?> onchange="document.getElementById('pro').hidden=false">Professionnel</label>
    </div>
  </div>
  <div class="row">
    <div class="field"><label>Nom complet / raison sociale *</label><input class="input" name="name" value="<?= e($v('name')) ?>" required maxlength="150"></div>
    <div class="field"><label>Téléphone *</label><input class="input mono" name="phone" value="<?= e($v('phone')) ?>" required placeholder="07 58 21 44 90" inputmode="tel"></div>
  </div>
  <div class="row">
    <div class="field"><label>E-mail</label><input class="input" type="email" name="email" value="<?= e($v('email')) ?>"></div>
    <div class="field"><label>Canal préféré</label>
      <select class="input" name="preferred_channel"><?php foreach (['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail'] as $k => $l): ?><option value="<?= $k ?>"<?= selected($v('preferred_channel', 'sms'), $k) ?>><?= $l ?></option><?php endforeach ?></select>
    </div>
  </div>
  <div class="field"><label>Adresse / quartier</label><input class="input" name="address" value="<?= e($v('address')) ?>" placeholder="Bonamoussadi, rue des Palmiers"></div>
  <div id="pro" class="row" <?= $isPro ? '' : 'hidden' ?>>
    <div class="field"><label>Plafond d'encours (FCFA)</label><input class="input mono" type="number" min="0" step="10000" name="credit_limit" value="<?= e($v('credit_limit', 0)) ?>"></div>
    <div class="field"><label>Délai de paiement (jours)</label><input class="input mono" type="number" min="0" name="payment_terms_days" value="<?= e($v('payment_terms_days', 30)) ?>"></div>
  </div>
  <div class="field"><label>Préférences de traitement</label><textarea class="input" name="preferences" placeholder="Amidon léger, rendu sur cintre…"><?= e($v('preferences')) ?></textarea></div>
  <div class="field"><label>Note interne</label><textarea class="input" name="notes"><?= e($v('notes')) ?></textarea></div>
  <div class="row" style="align-items:center">
    <label class="check"><input type="checkbox" name="is_vip" value="1"<?= checked((bool)$v('is_vip', 0)) ?>> Client VIP</label>
    <?php if (!$c): ?><div class="field" style="max-width:260px"><label>Parrainé par (téléphone)</label><input class="input mono" name="referrer_phone" value="<?= e(old('referrer_phone')) ?>"></div><?php endif ?>
  </div>
  <div class="row"><button class="btn primary lg"><?= $c ? 'Enregistrer' : 'Créer le client' ?></button><a class="btn lg" href="<?= $c ? '/clients/' . $c['id'] : '/clients' ?>">Annuler</a></div>
</form>
