<div class="head"><span class="mono small muted">05</span><h1>Dérogations qualité</h1>
  <span class="small muted">Pièces passées à l'emballage sans contrôle conforme, sur décision motivée d'un responsable.</span>
  <div class="actions"><a class="btn" href="/qualite">Qualité</a> <a class="btn" href="/qualite/sinistres">Sinistres</a></div></div>
<div class="card scroll"><table class="t">
  <thead><tr><th>Date</th><th>Pièce</th><th>Client</th><th>Motif</th><th>Autorisée par</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="mono small"><?= dt($r['created_at'], 'd/m H:i') ?></td>
    <td><a class="mono strong" href="/scan?code=<?= urlencode($r['code']) ?>"><?= e($r['code']) ?></a><div class="small"><?= e($r['label']) ?></div></td>
    <td><?= e($r['client']) ?></td><td><?= e($r['reason']) ?></td><td><?= e($r['authoriser'] ?? '—') ?></td></tr>
  <?php endforeach ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted">Aucune dérogation accordée.</td></tr><?php endif ?>
  </tbody></table></div>
