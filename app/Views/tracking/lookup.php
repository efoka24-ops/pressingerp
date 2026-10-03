<div class="form" style="margin-top:12px">
  <h1 style="font-size:24px">Suivre ma commande</h1>
  <span class="muted">Saisissez le numéro inscrit sur votre ticket et votre téléphone.</span>
  <form method="post" action="/suivi" class="card pad form">
    <?= csrf_field() ?>
    <div class="field"><label>N° de commande</label><input class="input lg mono" name="number" value="<?= e(old('number')) ?>" placeholder="PR-2026-000124" required></div>
    <div class="field"><label>Téléphone</label><input class="input lg mono" name="phone" value="<?= e(old('phone')) ?>" placeholder="07 58 21 44 90" inputmode="tel" required></div>
    <button class="btn primary lg block">Voir ma commande</button>
  </form>
</div>
