<?php $f = flash(); ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Pressing') ?></title>
<meta name="csrf" content="<?= e(App\Core\Csrf::token()) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body style="background:var(--panel)">
<div class="public">
  <div class="brand"><div class="logo">P</div><span>Pressing</span></div>
  <?php if ($f): ?><div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endif ?>
  <?= $content ?>
</div>
<script src="/assets/app.js"></script>
</body>
</html>
<?php unset($_SESSION['_old']); ?>
