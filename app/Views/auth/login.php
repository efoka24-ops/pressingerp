<?php $f = flash(); ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion · Pressing ERP</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="login">
  <div class="hero">
    <div class="brand"><div class="logo" style="width:36px;height:36px;font-size:16px">P</div><span style="font-size:18px">Pressing</span></div>
    <div style="margin-top:auto;display:flex;flex-direction:column;gap:16px">
      <h1>Chaque pièce, suivie de la réception au retrait.</h1>
      <span style="color:#A9AEAD">Gestion de pressing : réception, atelier, caisse, livraison.</span>
    </div>
    <span class="mono small" style="margin-top:40px;color:#6B706F">v1.0 · PHP <?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?></span>
  </div>
  <div class="panel">
    <form method="post" action="/login" class="form">
      <?= csrf_field() ?>
      <div><h1 style="font-size:24px">Connexion</h1><span class="muted small">Saisissez votre nom d'utilisateur et votre mot de passe.</span></div>
      <?php if ($f): ?><div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endif ?>
      <div class="field"><label for="login">Nom d'utilisateur</label><input class="input lg" id="login" name="login" value="<?= e(old('login')) ?>" autocomplete="username" required autofocus></div>
      <?php if ($pinMode): ?>
        <div class="field"><label for="pin">Code PIN</label><input class="input lg mono" id="pin" name="pin" type="password" inputmode="numeric" pattern="\d{4,6}" autocomplete="off" required></div>
      <?php else: ?>
        <div class="field"><label for="password">Mot de passe</label><input class="input lg" id="password" name="password" type="password" autocomplete="current-password" required></div>
      <?php endif ?>
      <button class="btn primary lg block">Se connecter</button>
      <div class="small">
        <a href="/guide.html">Guide d'utilisation</a> · <a href="/suivi">Suivre ma commande</a> · <a href="/ouvrir-un-pressing">Ouvrir mon pressing</a><br>
        <?php if ($pinMode): ?><a href="/login">Connexion par mot de passe</a><?php else: ?><a href="/login?mode=pin">Connexion par code PIN (poste atelier)</a><?php endif ?>
      </div>
    </form>
  </div>
</div>
</body>
</html>
<?php unset($_SESSION['_old']); ?>
