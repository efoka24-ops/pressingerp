<div class="form" style="margin-top:12px">
  <h1 style="font-size:26px">Où en est mon linge ?</h1>
  <span class="muted">Saisissez le numéro de votre ticket de dépôt et votre téléphone. Vous pouvez aussi scanner le QR code imprimé sur le ticket.</span>
  <form method="post" action="/suivi" class="card pad form">
    <?= csrf_field() ?>
    <div class="field"><label>Numéro du ticket</label><input class="input lg mono" name="number" value="<?= e(old('number')) ?>" placeholder="PR-2026-000124" required autocomplete="off"></div>
    <div class="field"><label>Votre téléphone</label><input class="input lg mono" name="phone" value="<?= e(old('phone')) ?>" placeholder="6 70 12 34 56" inputmode="tel" required></div>
    <button class="btn primary lg block">Suivre ma commande</button>
  </form>
  <a class="card pad" href="/guide.html" style="display:flex;flex-direction:column;gap:2px;text-decoration:none;color:inherit">
    <b>Guide d'utilisation</b>
    <span class="small muted">Comment utiliser la plateforme, métier par métier : réception, atelier, caisse, livraison, direction. Et comment un client suit sa commande.</span>
  </a>
  <div class="small center">
    <a href="/login">Espace du personnel</a> · <a href="/ouvrir-un-pressing">Ouvrir mon pressing sur la plateforme</a>
  </div>
</div>
