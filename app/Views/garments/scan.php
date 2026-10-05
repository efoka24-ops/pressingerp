<?php
use App\Domain\GarmentStatus;
use App\Domain\IncidentType;
use App\Domain\ServiceLevel;
use App\Domain\Step;
?>
<div class="head"><span class="mono small muted">04</span><h1>Scanner une pièce</h1><div class="actions"><a class="btn" href="/production">Tableau atelier</a></div></div>

<div class="split" style="grid-template-columns:minmax(0,560px) minmax(0,1fr)">
  <div class="stack">
    <form method="get" action="/scan" class="row">
      <div class="field"><input class="input lg mono" name="code" id="scan" value="<?= e($code) ?>" placeholder="Scannez le QR ou saisissez PR-2026-000124-03" autofocus autocomplete="off"></div>
      <button class="btn dark lg">Afficher</button>
    </form>

    <?php if ($candidates): ?>
      <div class="card pad"><div class="card-h"><h2>Code illisible ? Choisissez la pièce</h2></div>
        <?php foreach ($candidates as $cd): ?>
          <a class="tile" style="min-height:48px;margin:4px 0" href="/scan?code=<?= urlencode($cd['code']) ?>"><b class="mono"><?= e($cd['code']) ?></b><span><?= e($cd['label']) ?> · <?= e($cd['client']) ?> · <?= e(Step::from($cd['step'])->label()) ?></span></a>
        <?php endforeach ?>
      </div>
    <?php endif ?>

    <?php if ($g):
      $step = Step::from($g['step']);
      $st = GarmentStatus::from($g['status']);
      $r = risk($g['promised_at'], $g['order_status']); ?>
      <div class="card pad form">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
          <div><div class="mono strong" style="font-size:17px"><?= e($g['code']) ?></div><div><?= e($g['label']) ?><?= $g['color'] ? ' · ' . e($g['color']) : '' ?></div></div>
          <span class="badge dark"><?= e($step->label()) ?></span>
        </div>
        <div class="chips">
          <?php if ($g['material']): ?><span class="badge"><?= e($g['material']) ?></span><?php endif ?>
          <?php if ($g['brand']): ?><span class="badge"><?= e($g['brand']) ?></span><?php endif ?>
          <?php if ($g['service_level'] !== 'standard'): ?><span class="badge orange"><?= e(ServiceLevel::from($g['service_level'])->label()) ?></span><?php endif ?>
          <?php if ($g['rework_count']): ?><span class="badge red">Reprise × <?= (int)$g['rework_count'] ?></span><?php endif ?>
          <span class="badge <?= $st->tone() ?>"><?= e($st->label()) ?><?= $g['operator'] ? ' · ' . e($g['operator']) : '' ?></span>
        </div>
        <div class="small muted">Parcours<?= $treatment ? ' « ' . e($treatment) . ' »' : '' ?> :
          <?php foreach ($route as $rs): if ($rs === Step::Retire) continue; $done = $rs->index() < $step->index(); $cur = $rs === $step; ?>
            <span style="<?= $cur ? 'font-weight:700;color:var(--ink)' : ($done ? 'text-decoration:line-through' : '') ?>"><?= e($rs->label()) ?></span><?= $rs === Step::Pret ? '' : ' › ' ?>
          <?php endforeach ?>
        </div>
        <?php foreach ($incidents as $inc): if ($inc['resolved_at']) continue; ?>
          <div class="note <?= $inc['severity'] === 'critical' ? 'warn' : '' ?>"><b><?= e(IncidentType::tryFrom($inc['type'])?->label() ?? $inc['type']) ?></b><?= $inc['severity'] === 'critical' ? ' · CRITIQUE' : '' ?> — <?= e($inc['note']) ?> <span class="small muted">(<?= e($inc['reporter'] ?? '—') ?>, <?= dt($inc['created_at'], 'd/m H:i') ?>)</span></div>
        <?php endforeach ?>
        <?php if ($g['damages']): ?><div class="note warn">Constaté à la réception : <?= e($g['damages']) ?><?= $g['photo_path'] ? ' · <a href="' . e($g['photo_path']) . '" target="_blank">voir la photo</a>' : '' ?></div><?php endif ?>
        <div>
          <div class="kv"><span>Client</span><span><?= e($g['client']) ?></span></div>
          <div class="kv"><span>Commande</span><span class="mono"><?= e($g['number']) ?> · <?= (int)$g['ready_pcs'] ?> / <?= (int)$g['total_pcs'] ?> prêtes</span></div>
          <div class="kv"><span>Promis</span><span class="mono <?= $r ?>"><?= dot($r) ?> <?= fdate($g['promised_at']) ?></span></div>
          <div class="kv"><span>À cette étape depuis</span><span class="mono"><?= since($g['step_since']) ?></span></div>
          <?php if ($g['rail']): ?><div class="kv"><span>Emplacement</span><span class="mono"><?= e($g['rail']) ?></span></div><?php endif ?>
        </div>

        <?php if ($step === Step::Controle): ?>
          <?php if (can('quality')): ?><a class="btn primary xl block" href="/qualite/controle/<?= $g['id'] ?>">Ouvrir le contrôle qualité</a><?php else: ?><div class="note">En attente du contrôle qualité.</div><?php endif ?>
          <?php if (\App\Core\Auth::isManager() && can('quality', 'validate')): ?>
          <details><summary class="small">Dérogation (responsable) : passer sans contrôle conforme</summary>
            <form method="post" action="/pieces/<?= $g['id'] ?>/derogation" class="form"><?= csrf_field() ?>
              <div class="field"><input class="input" name="reason" minlength="10" placeholder="Motif détaillé (obligatoire, tracé dans l'audit)" required></div>
              <button class="btn ghost-danger">Accorder la dérogation</button>
            </form>
          </details>
          <?php endif ?>
        <?php elseif (in_array($step, [Step::Pret, Step::Retire], true)): ?>
          <div class="note"><?= $step === Step::Pret ? 'Pièce prête, en attente de retrait.' : 'Pièce remise au client.' ?></div>
        <?php elseif ($st === GarmentStatus::Bloque): ?>
          <form method="post" action="/pieces/<?= $g['id'] ?>/action" class="form"><?= csrf_field() ?><input type="hidden" name="do" value="unblock"><div class="field"><label>Résolution</label><input class="input" name="resolution" placeholder="ex. détachant spécial appliqué, machine réparée"></div><button class="btn primary xl block">Lever l'incident</button></form>
        <?php else: ?>
          <?php if (in_array($st, [GarmentStatus::ATraiter, GarmentStatus::AReprendre], true)): ?>
            <form method="post" action="/pieces/<?= $g['id'] ?>/action"><?= csrf_field() ?><input type="hidden" name="do" value="start"><button class="btn primary xl block">Prendre en charge</button></form>
          <?php endif ?>
          <form method="post" action="/pieces/<?= $g['id'] ?>/action" class="form">
            <?= csrf_field() ?><input type="hidden" name="do" value="complete">
            <?php if ($step === Step::Lavage): ?><div class="field"><label>Machine / lot</label><input class="input mono" name="machine" placeholder="M2 · L-0412"></div><?php endif ?>
            <?php if ($step === Step::Emballage): ?><div class="field"><label>Rail de rangement *</label><input class="input mono" name="rail" placeholder="R-07" required></div><?php endif ?>
            <button class="btn <?= $st === GarmentStatus::EnCours ? 'dark' : '' ?> xl block">Terminer « <?= e($step->label()) ?> » → <?= e($next?->label() ?? '') ?></button>
          </form>
          <div class="row">
            <?php if (in_array($step, [Step::Detachage, Step::Sechage, Step::Finition], true)): ?>
              <form method="post" action="/pieces/<?= $g['id'] ?>/action"><?= csrf_field() ?><input type="hidden" name="do" value="skip"><button class="btn">Étape non nécessaire</button></form>
            <?php endif ?>
            <form method="post" action="/pieces/<?= $g['id'] ?>/action" class="row" style="flex:1">
              <?= csrf_field() ?><input type="hidden" name="do" value="block">
              <div class="field"><select class="input" name="type" required><option value="">Type d'incident…</option><?php foreach (IncidentType::cases() as $it): ?><option value="<?= $it->value ?>"><?= e($it->label()) ?><?= $it->critical() ? ' (critique)' : '' ?></option><?php endforeach ?></select></div>
              <div class="field"><input class="input" name="note" placeholder="Précisez : où, quoi, depuis quand…" required></div>
              <button class="btn ghost-danger">Signaler</button>
            </form>
          </div>
        <?php endif ?>
        <?php if (can('production') && $step !== Step::Retire): ?>
        <details><summary class="small">Déclarer la pièce perdue ou endommagée</summary>
          <form method="post" action="/pieces/<?= $g['id'] ?>/sinistre" class="form"><?= csrf_field() ?>
            <div class="field"><select class="input" name="kind"><option value="perdu">Pièce perdue</option><option value="endommage">Pièce endommagée</option></select></div>
            <div class="field"><input class="input" name="description" placeholder="Circonstances (où, quand, comment)" required></div>
            <button class="btn ghost-danger">Déclarer le sinistre</button>
          </form>
        </details>
        <?php endif ?>
      </div>
    <?php endif ?>
  </div>

  <?php if ($g): ?>
  <div class="card pad">
    <div class="card-h"><h2>Historique</h2></div>
    <div class="timeline">
      <?php foreach ($events as $i => $ev): $isCur = $i === 0 && !in_array($g['step'], ['pret', 'retire'], true); ?>
        <span class="pt <?= in_array($ev['action'], ['incident', 'reprise'], true) ? 'red' : ($isCur ? 'cur' : '') ?>"></span>
        <div class="ev"><b style="font-weight:<?= $isCur ? 600 : 400 ?>"><?= e(Step::from($ev['step'])->label()) ?> · <?= e(event_label($ev['action'])) ?></b><span><?= e(implode(' · ', array_filter([$ev['machine'], $ev['note'], $ev['user']]))) ?></span></div>
        <time><?= dt($ev['created_at'], 'd/m H:i') ?></time>
      <?php endforeach ?>
    </div>
  </div>
  <?php endif ?>
</div>
