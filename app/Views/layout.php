<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Domain\Module;

$u = Auth::user();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$f = flash();
\App\Services\AlertService::lazyTick();
\App\Services\MessageService::lazyDispatch();
$alertCount = $u ? \App\Services\AlertService::count($u) : 0;
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? '') ? $title . ' · ' : '') ?>Pressing ERP</title>
<meta name="csrf" content="<?= e(Csrf::token()) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('/assets/app.css')) ?>">
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand"><div class="logo">P</div><div>Pressing<small><?= e($u['agency_name'] ?? '') ?></small></div></div>
    <nav class="nav">
      <?php foreach (Module::ALL as $code => [$num, $label, $href]): ?>
        <?php if (!Auth::can($code)) continue; $on = str_starts_with($path, $href) || ($code === 'production' && $path === '/scan') || ($code === 'commercial' && $path === '/recouvrement'); $badge = Module::badge($code); ?>
        <a href="<?= e($href) ?>" class="<?= $on ? 'on' : '' ?>"><span class="n"><?= e($num) ?></span><?= e($label) ?><?php if ($badge): ?><span class="count"><?= $badge ?></span><?php endif ?></a>
      <?php endforeach ?>
    </nav>
    <div class="me">
      <div class="avatar"><?= e(initials($u['name'] ?? '?')) ?></div>
      <div class="who"><b><?= e($u['name'] ?? '') ?></b><span style="color:#8A8F8E"><?= e(Auth::role()?->label()) ?></span></div>
      <form method="post" action="/logout"><?= csrf_field() ?><button class="btn sm" style="background:transparent;color:#C9CCCB;border-color:#3A3E40" title="Se déconnecter">Sortir</button></form>
    </div>
  </aside>
  <main class="main">
    <header class="top">
      <form action="/recherche"><input class="input" name="q" placeholder="Téléphone, nom, n° commande, QR pièce, facture…  (F2)" value="<?= e($path === '/recherche' ? ($_GET['q'] ?? '') : '') ?>" autocomplete="off"></form>
      <?php if (Auth::can('orders')): ?><a class="btn primary" href="/commandes/nouvelle">+ Commande <span class="mono small" style="opacity:.7">F1</span></a> <a class="btn" href="/hors-ligne" title="Saisir des commandes sans réseau">Hors-ligne</a><?php endif ?>
      <?php if (Auth::role()?->canSwitchAgency()): ?><form method="post" action="/agence/changer" style="display:flex;gap:4px"><?= csrf_field() ?><select class="input" name="agency_id" onchange="this.form.submit()" title="Agence de travail"><?php foreach (\App\Core\Database::all('SELECT id, name FROM agencies ORDER BY is_workshop, name') as $ag): ?><option value="<?= (int)$ag['id'] ?>"<?= (int)$ag['id'] === Auth::agencyId() ? ' selected' : '' ?>><?= e($ag['name']) ?></option><?php endforeach ?></select></form><?php endif ?>
      <a class="btn" href="/alertes" id="alert-bell" style="margin-left:auto" title="Alertes">Alertes<?php if ($alertCount): ?> <span class="count" id="alert-count"><?= $alertCount ?></span><?php endif ?></a>
      <span class="mono small muted"><?= e(day_name()) ?> <?= date('d/m/Y · H:i') ?></span>
    </header>
    <div class="content">
      <?php if ($f): ?><div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endif ?>
      <?= $content ?>
    </div>
  </main>
</div>
<script src="<?= e(asset('/assets/app.js')) ?>"></script>
<script>setInterval(function () { fetch('/api/alertes/compte', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { var b = document.getElementById('alert-bell'); if (!b) return; var c = document.getElementById('alert-count'); if (j.n > 0) { if (!c) { c = document.createElement('span'); c.className = 'count'; c.id = 'alert-count'; b.appendChild(document.createTextNode(' ')); b.appendChild(c); } c.textContent = j.n; } else if (c) { c.remove(); } }).catch(function () {}); }, 30000);</script>
</body>
</html>
<?php unset($_SESSION['_old']); ?>
