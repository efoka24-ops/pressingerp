<div class="head"><a class="small" href="/admin">← Administration</a><h1>Demandes d'ouverture de pressing</h1></div>
<div class="tabs">
  <?php foreach ($statuses as $k => $l): ?><a class="tab<?= $k === $status ? ' on' : '' ?>" href="/admin/demandes?statut=<?= $k ?>"><?= e($l) ?> <span class="count"><?= (int)($counts[$k] ?? 0) ?></span></a><?php endforeach ?>
</div>
<div class="stack">
<?php foreach ($rows as $r): ?>
  <div class="card pad form">
    <div class="kv"><span><b><?= e($r['pressing_name']) ?></b> · <?= e($r['city']) ?><br><span class="small muted"><?= e($r['address']) ?> · <?= e($r['phone']) ?></span></span>
      <span class="small muted">demandée le <?= dt($r['created_at'], 'd/m/Y H:i') ?></span></div>
    <div class="kv"><span>Responsable</span><span class="right"><?= e($r['manager_name']) ?> · <?= e($r['email']) ?></span></div>
    <?php if ($r['message']): ?><div class="note"><?= e($r['message']) ?></div><?php endif ?>
    <?php if ($r['status'] === 'en_attente'): ?>
      <form method="post" action="/admin/demandes/<?= (int)$r['id'] ?>/valider" class="row" data-confirm="Valider et envoyer les identifiants à <?= e($r['email']) ?> ?" style="gap:8px;flex-wrap:wrap;align-items:end"><?= csrf_field() ?>
        <div class="field"><label>Code agence</label><input class="input mono" name="code" style="width:110px" value="<?= e($suggest($r['city'])) ?>"></div>
        <div class="field"><label>Nom de l'agence</label><input class="input" name="name" value="<?= e($r['pressing_name']) ?>"></div>
        <button class="btn primary">Valider et envoyer les identifiants</button>
      </form>
      <form method="post" action="/admin/demandes/<?= (int)$r['id'] ?>/refuser" class="row" style="gap:8px;flex-wrap:wrap;align-items:end"><?= csrf_field() ?>
        <div class="field" style="flex:1"><label>Motif du refus (communiqué au demandeur)</label><input class="input" name="reason" required minlength="8"></div>
        <button class="btn">Refuser</button>
      </form>
    <?php else: ?>
      <div class="kv"><span><?= $r['status'] === 'validee' ? 'Validée' : 'Refusée' ?> par <?= e($r['reviewer'] ?? '—') ?> le <?= dt($r['reviewed_at'], 'd/m/Y H:i') ?></span>
        <span class="small muted">e-mail : <?= e(['envoye' => 'envoyé', 'echec' => 'échec', 'non_configure' => 'canal non configuré'][$r['mail_status']] ?? '—') ?></span></div>
      <?php if ($r['reject_reason']): ?><div class="note">Motif : <?= e($r['reject_reason']) ?></div><?php endif ?>
    <?php endif ?>
  </div>
<?php endforeach ?>
<?php if (!$rows): ?><div class="card pad muted">Aucune demande dans cet onglet.</div><?php endif ?>
</div>
