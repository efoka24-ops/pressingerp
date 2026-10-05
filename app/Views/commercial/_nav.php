<?php $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH); ?>
<div class="head">
  <span class="mono small muted">07</span><h1>Commercial &amp; Recouvrement</h1>
  <?php if (!empty($actions)): ?><div class="actions"><?= $actions ?></div><?php endif ?>
</div>
<nav class="subnav">
  <a href="/commercial" class="<?= $path === '/commercial' ? 'on' : '' ?>">Contrats</a>
  <a href="/commercial/factures" class="<?= str_starts_with((string)$path, '/commercial/factures') ? 'on' : '' ?>">Facturation</a>
  <a href="/commercial/devis" class="<?= str_starts_with((string)$path, '/commercial/devis') ? 'on' : '' ?>">Devis</a>
  <a href="/recouvrement" class="<?= $path === '/recouvrement' ? 'on' : '' ?>">Recouvrement</a>
</nav>
