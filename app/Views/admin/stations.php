<?php use App\Core\Auth; $w = Auth::can('admin', 'update'); ?>
<div class="head"><span class="mono small muted">11</span><h1>Postes hors-ligne</h1>
  <span class="small muted">Chaque poste reçoit des plages de numéros réservées. Les numéros non utilisés restent tracés, jamais réattribués.</span></div>
<div class="card scroll"><table class="t">
  <thead><tr><th>Poste</th><th>Agence</th><th class="num">Numéros émis</th><th class="num">Synchronisées</th><th class="num">Rejetées</th><th class="num">Jamais utilisés</th><th>Dernière synchro</th><th>État</th><?php if ($w): ?><th></th><?php endif ?></tr></thead>
  <tbody>
  <?php foreach ($stations as $s): ?>
    <tr class="<?= $s['active'] ? '' : 'warn' ?>">
      <td class="strong"><?= e($s['label']) ?></td><td><?= e($s['agency']) ?></td>
      <td class="num mono"><?= (int)$s['issued'] ?></td><td class="num mono"><?= (int)$s['synced'] ?></td>
      <td class="num mono <?= $s['rejected'] ? 'red' : '' ?>"><?= (int)$s['rejected'] ?></td><td class="num mono"><?= (int)$s['unused'] ?></td>
      <td class="mono small"><?= $s['last_sync_at'] ? dt($s['last_sync_at'], 'd/m H:i') : '—' ?></td>
      <td><?= $s['active'] ? 'Actif' : 'Désactivé' ?></td>
      <?php if ($w): ?><td><?php if ($s['active']): ?>
        <form method="post" action="/admin/postes/<?= (int)$s['id'] ?>" class="row" style="gap:6px"><?= csrf_field() ?><input class="input" name="reason" placeholder="Motif (ex. tablette perdue)" required><button class="btn sm">Désactiver</button></form>
      <?php endif ?></td><?php endif ?>
    </tr>
  <?php endforeach ?>
  <?php if (!$stations): ?><tr><td colspan="9" class="muted">Aucun poste autorisé. Un responsable autorise un poste depuis la page « Réception hors-ligne » de cet appareil.</td></tr><?php endif ?>
  </tbody></table></div>

<?php if ($rejected): ?>
<div class="card scroll"><div class="card-h"><h2>Commandes hors-ligne rejetées</h2><span class="small muted">Les vêtements sont en boutique : à ressaisir manuellement en ligne.</span></div>
  <table class="t"><thead><tr><th>Numéro</th><th>Poste</th><th>Reçue le</th><th>Cause</th></tr></thead><tbody>
  <?php foreach ($rejected as $r): ?><tr><td class="mono strong"><?= e($r['number']) ?></td><td><?= e($r['label']) ?></td><td class="mono small"><?= dt($r['received_at'], 'd/m H:i') ?></td><td><?= e($r['message']) ?></td></tr><?php endforeach ?>
  </tbody></table></div>
<?php endif ?>
