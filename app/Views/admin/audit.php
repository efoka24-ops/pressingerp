<div class="head"><span class="mono small muted">11</span><h1>Journal d'audit</h1><a class="btn sm" href="/admin/audit?verifier=1">Vérifier la chaîne</a></div>
<?php if ($verify): ?>
  <div class="flash <?= $verify['broken_id'] === null ? 'ok' : 'err' ?>">
    <?= $verify['broken_id'] === null ? 'Chaîne intègre : ' . $verify['checked'] . ' événements vérifiés.' : 'ALTÉRATION détectée à l\'événement n° ' . $verify['broken_id'] . ' (' . $verify['checked'] . ' événements valides avant).' ?>
  </div>
<?php endif ?>
<form class="row" style="gap:8px;margin-bottom:12px"><input class="input" name="action" placeholder="Action (ex. settings, order.cancel)" value="<?= e($_GET['action'] ?? '') ?>"><input class="input" name="entity" placeholder="Entité" value="<?= e($_GET['entity'] ?? '') ?>"><button class="btn sm">Filtrer</button></form>
<div class="card scroll"><table class="t">
  <thead><tr><th>#</th><th>Date</th><th>Utilisateur</th><th>Action</th><th>Objet</th><th>Ancienne valeur</th><th>Nouvelle valeur</th><th>Motif</th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr><td class="mono small"><?= $r['id'] ?></td><td class="mono small"><?= e($r['created_at']) ?></td><td><?= e($r['user_name'] ?? '—') ?></td><td class="mono"><?= e($r['action']) ?></td>
    <td class="small"><?= e($r['entity']) ?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?></td>
    <td class="mono small"><?= e($r['old_value'] ?? '') ?></td><td class="mono small"><?= e($r['new_value'] ?? '') ?></td><td class="small"><?= e($r['reason'] ?? '') ?></td></tr>
  <?php endforeach ?></tbody></table></div>
