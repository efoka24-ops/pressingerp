<?php use App\Domain\Step; ?>
<div class="head"><span class="mono small muted">05</span><h1>Qualité</h1><span class="small muted">7 derniers jours</span><div class="actions"><a class="btn" href="/qualite/sinistres">Sinistres</a> <a class="btn" href="/qualite/derogations">Dérogations</a></div></div>

<div class="grid g4">
  <div class="card kpi"><span class="l">Taux de reprise</span><span class="v <?= $rate > $target ? 'orange' : 'green' ?>"><?= str_replace('.', ',', (string)$rate) ?> %</span><span class="small muted">objectif ≤ <?= $target ?> %</span></div>
  <div class="card kpi"><span class="l">Pièces contrôlées</span><span class="v"><?= money($checked) ?></span></div>
  <div class="card kpi"><span class="l">En attente de contrôle</span><span class="v"><?= count($queue) ?></span></div>
  <div class="card kpi"><span class="l">Dédommagements (30 j)</span><span class="v"><?= money($compensation) ?></span></div>
</div>

<div class="split left">
  <div class="stack">
    <div class="card pad form">
      <div class="card-h"><h2>Motifs de reprise</h2></div>
      <?php if (!$reasons): ?><span class="small muted">Aucune reprise sur la période.</span><?php endif ?>
      <?php $max = max(1, ...array_column($reasons, 'n') ?: [1]); foreach ($reasons as $i => $r): ?>
        <div class="hbar"><span><?= e($r['reason']) ?></span><i style="width:<?= max(4, (int)round($r['n'] * 100 / $max)) ?>%;background:<?= $i === 0 ? 'var(--red)' : ($i < 3 ? '#E8B868' : '#DAD6CC') ?>"></i><span class="mono right"><?= $r['n'] ?></span></div>
      <?php endforeach ?>
      <?php if ($hotspot): ?><div class="note">Les reprises renvoient le plus souvent vers <b><?= e(Step::from($hotspot['back_to'])->label()) ?></b> (<?= (int)$hotspot['n'] ?>) : vérifier le poste, le produit ou la formation.</div><?php endif ?>
    </div>

    <div class="card">
      <div class="card-h"><h2>File de contrôle</h2><span class="small muted"><?= count($queue) ?></span></div>
      <?php if (!$queue): ?><div class="empty">Aucune pièce à contrôler.</div><?php else: ?>
      <table class="t"><tbody>
        <?php foreach (array_slice($queue, 0, 20) as $g): $r = risk($g['promised_at']); ?>
          <tr><td><?= dot($r) ?></td><td class="mono"><a class="row-link" href="/qualite/controle/<?= $g['id'] ?>"><?= e($g['code']) ?></a><div class="small muted"><?= e($g['label']) ?> · <?= e($g['client']) ?></div></td><td class="right mono small <?= $r ?>"><?= fdate($g['promised_at']) ?></td></tr>
        <?php endforeach ?>
      </tbody></table>
      <div style="padding:12px 18px"><a class="btn primary block" href="/qualite/controle/<?= $queue[0]['id'] ?>">Commencer le contrôle</a></div>
      <?php endif ?>
    </div>
  </div>

  <div class="stack" id="reclamations">
    <div class="card scroll">
      <div class="card-h"><h2>Réclamations clients</h2></div>
      <?php if (!$complaints): ?><div class="empty">Aucune réclamation.</div><?php else: ?>
      <table class="t">
        <thead><tr><th>N°</th><th>Client</th><th>Objet</th><th>Statut</th><th>Responsable</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($complaints as $cp): $age = (int)((time() - strtotime($cp['created_at'])) / 86400); ?>
          <tr>
            <td class="mono"><?= e($cp['number']) ?></td>
            <td><a href="/clients/<?= $cp['client_id'] ?>"><?= e($cp['client']) ?></a><?= $cp['order_number'] ? '<div class="mono small muted">' . e($cp['order_number']) . '</div>' : '' ?></td>
            <td><?= e($cp['subject']) ?><?= $cp['resolution'] ? '<div class="small muted">' . e($cp['resolution']) . '</div>' : '' ?></td>
            <td class="<?= $cp['status'] === 'cloturee' ? 'green' : ($cp['status'] === 'ouverte' ? 'red' : 'orange') ?>"><?= e($statuses[$cp['status']] ?? $cp['status']) ?><?= $cp['status'] !== 'cloturee' ? " · $age j" : '' ?></td>
            <td><?= e($cp['assignee'] ?? '—') ?></td>
            <td>
              <?php if ($cp['status'] !== 'cloturee'): ?>
              <details><summary class="small accent" style="cursor:pointer">Traiter</summary>
                <form method="post" action="/qualite/reclamations/<?= $cp['id'] ?>" class="form" style="min-width:260px;padding-top:8px">
                  <?= csrf_field() ?>
                  <select class="input" name="status"><?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>"<?= selected($cp['status'], $k) ?>><?= e($l) ?></option><?php endforeach ?></select>
                  <input class="input mono" name="compensation" type="number" min="0" step="500" value="<?= (int)$cp['compensation'] ?>" placeholder="Dédommagement">
                  <textarea class="input" name="resolution" placeholder="Résolution"><?= e($cp['resolution']) ?></textarea>
                  <button class="btn sm primary">Enregistrer</button>
                </form>
              </details>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </div>

    <form method="post" action="/qualite/reclamations" class="card pad form">
      <?= csrf_field() ?>
      <h2>Nouvelle réclamation</h2>
      <div class="row">
        <div class="field"><label>N° de commande *</label><input class="input mono" name="order_number" placeholder="PR-2026-000107" required></div>
        <div class="field"><label>Responsable</label><select class="input" name="assigned_to"><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach ?></select></div>
      </div>
      <div class="field"><label>Objet *</label><input class="input" name="subject" required maxlength="200" placeholder="Chemisier décoloré, pièce manquante…"></div>
      <button class="btn dark">Enregistrer la réclamation</button>
    </form>
  </div>
</div>
