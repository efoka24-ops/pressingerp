<?php if ($forced): ?>
<div class="small muted">Première connexion</div>
<h1 style="font-size:24px">Choisissez votre mot de passe</h1>
<p class="muted">Le mot de passe provisoire qui vous a été remis ne doit plus être utilisé. Choisissez-en un nouveau pour continuer.</p>
<?php else: ?>
<div class="head"><h1>Mon mot de passe</h1></div>
<?php endif ?>
<form method="post" action="/mot-de-passe" class="card pad form" style="max-width:480px">
  <?= csrf_field() ?>
  <div class="field"><label>Mot de passe actuel</label><input class="input lg" type="password" name="current" autocomplete="current-password" required autofocus></div>
  <div class="field"><label>Nouveau mot de passe (10 caractères minimum, lettres et chiffres)</label><input class="input lg" type="password" name="new" autocomplete="new-password" required minlength="10"></div>
  <div class="field"><label>Confirmer le nouveau mot de passe</label><input class="input lg" type="password" name="confirm" autocomplete="new-password" required minlength="10"></div>
  <button class="btn primary lg block">Enregistrer</button>
</form>
<?php if ($forced): ?>
<form method="post" action="/logout" style="margin-top:12px"><?= csrf_field() ?><button class="btn sm">Me déconnecter</button></form>
<?php endif ?>
