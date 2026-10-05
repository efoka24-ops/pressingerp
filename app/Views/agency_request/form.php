<div class="form" style="margin-top:12px">
  <h1 style="font-size:24px">Ouvrir mon pressing sur la plateforme</h1>
  <span class="muted">Remplissez cette demande. Elle est examinée par l'administrateur de la plateforme ; une fois validée, vous recevez par e-mail votre nom d'utilisateur et un mot de passe provisoire.</span>
  <form method="post" action="/ouvrir-un-pressing" class="card pad form">
    <?= csrf_field() ?>
    <div class="field"><label>Nom du pressing *</label><input class="input lg" name="pressing_name" value="<?= e(old('pressing_name')) ?>" required maxlength="100" placeholder="Pressing Zik"></div>
    <div class="row">
      <div class="field"><label>Ville *</label><input class="input" name="city" value="<?= e(old('city')) ?>" required maxlength="80" placeholder="Garoua"></div>
      <div class="field"><label>Téléphone du pressing *</label><input class="input mono" name="phone" value="<?= e(old('phone')) ?>" required inputmode="tel" placeholder="6 70 12 34 56"></div>
    </div>
    <div class="field"><label>Adresse *</label><input class="input" name="address" value="<?= e(old('address')) ?>" required maxlength="200" placeholder="Quartier, rue, repère (ex. Cité SIC, face au marché)"></div>
    <div class="row">
      <div class="field"><label>Prénom et nom du responsable *</label><input class="input" name="manager_name" value="<?= e(old('manager_name')) ?>" required maxlength="100" autocomplete="name"></div>
      <div class="field"><label>E-mail du responsable *</label><input class="input" type="email" name="email" value="<?= e(old('email')) ?>" required maxlength="150" autocomplete="email"></div>
    </div>
    <div class="field"><label>Précisions (facultatif)</label><textarea class="input" name="message" rows="3" maxlength="500"><?= e(old('message')) ?></textarea></div>
    <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Ne pas remplir</label><input name="website" tabindex="-1" autocomplete="off"></div>
    <button class="btn primary lg block">Envoyer ma demande</button>
  </form>
  <div class="small"><a href="/login">Déjà un compte ? Se connecter</a></div>
</div>
