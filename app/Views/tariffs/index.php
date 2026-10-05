<?php use App\Core\Auth; $w = Auth::can('pricing', 'update'); $c = Auth::can('pricing', 'create'); ?>
<div class="head"><span class="mono small muted">12</span><h1>Tarifs</h1>
  <span class="small muted">Priorité : contrat entreprise › agence › VIP › promotion › standard. Chaque prix modifié est versionné et audité.</span></div>

<?php foreach ($lists as $l): ?>
<div class="card scroll">
  <div class="card-h">
    <h2><?= e($l['name']) ?> <span class="tag"><?= e($kinds[$l['kind']] ?? $l['kind']) ?></span> <?= $l['active'] ? '' : '<span class="badge orange">désactivée</span>' ?></h2>
    <span class="small muted">
      <?= $l['agency'] ? 'Agence : ' . e($l['agency']) . ' · ' : '' ?><?= $l['client'] ? 'Client : ' . e($l['client']) . ' · ' : '' ?>
      <?= $l['valid_from'] ? 'du ' . e($l['valid_from']) . ' au ' . e($l['valid_to']) : '' ?>
      · <a href="/tarifs?histo=<?= (int)$l['id'] ?>">historique</a>
    </span>
  </div>
  <table class="t">
    <thead><tr><th>Article</th><th class="num">Prix actuel (FCFA)</th><?php if ($w): ?><th>Modifier (motif obligatoire)</th><?php endif ?></tr></thead>
    <tbody>
    <?php foreach ($articles as $a): $p = $l['prices'][(int)$a['id']] ?? null; $has = array_key_exists((int)$a['id'], $l['prices']); ?>
      <tr>
        <td class="strong"><?= e($a['name']) ?><?= $a['unit'] === 'm2' ? ' <span class="small muted">/ m²</span>' : '' ?></td>
        <td class="num mono"><?= $p === null ? '<span class="muted">' . ($has ? 'retiré' : '—') . '</span>' : money($p) ?></td>
        <?php if ($w): ?><td>
          <form method="post" action="/tarifs/prix" class="row" style="gap:6px;flex-wrap:nowrap"><?= csrf_field() ?>
            <input type="hidden" name="list_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="article_id" value="<?= (int)$a['id'] ?>">
            <input class="input mono" name="price" type="number" min="0" step="50" style="width:110px" placeholder="vide = retirer" value="<?= $p ?>">
            <input class="input" name="reason" placeholder="Motif" required style="width:180px"><button class="btn sm">Enregistrer</button>
          </form>
        </td><?php endif ?>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php if ($w && $l['kind'] !== 'standard'): ?>
  <form method="post" action="/tarifs/listes/<?= (int)$l['id'] ?>" class="row" style="gap:6px;padding:10px 18px"><?= csrf_field() ?>
    <input class="input" name="reason" placeholder="Motif" required><button class="btn sm"><?= $l['active'] ? 'Désactiver' : 'Activer' ?> cette grille</button>
  </form>
  <?php endif ?>
</div>
<?php endforeach ?>

<?php if ($history): ?>
<div class="card scroll"><div class="card-h"><h2>Historique des prix</h2></div>
  <table class="t"><thead><tr><th>Date</th><th>Article</th><th class="num">Prix</th><th>Par</th><th>Motif</th></tr></thead><tbody>
  <?php foreach ($history as $h): ?><tr><td class="mono small"><?= e($h['created_at']) ?></td><td><?= e($h['article']) ?></td><td class="num"><?= $h['price'] === null ? 'retiré' : money($h['price']) ?></td><td><?= e($h['user_name'] ?? '—') ?></td><td class="small"><?= e($h['reason'] ?? '') ?></td></tr><?php endforeach ?>
  </tbody></table></div>
<?php endif ?>

<?php if ($c): ?>
<form method="post" action="/tarifs/listes" class="card pad form"><?= csrf_field() ?>
  <h2>Nouvelle grille</h2>
  <div class="row">
    <div class="field"><label>Type</label><select class="input" name="kind"><?php foreach ($kinds as $k => $label): if ($k === 'standard') continue; ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Nom</label><input class="input" name="name" required placeholder="ex. Hôtel Ibis — contrat 2026"></div>
  </div>
  <div class="row">
    <div class="field"><label>Agence (tarif agence)</label><select class="input" name="agency_id"><option value="">—</option><?php foreach ($agencies as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Code client (contrat entreprise)</label><input class="input mono" name="client_code" placeholder="CL-2026-000012"></div>
    <div class="field"><label>Début (promotion)</label><input class="input" type="date" name="valid_from"></div>
    <div class="field"><label>Fin (promotion)</label><input class="input" type="date" name="valid_to"></div>
  </div>
  <button class="btn primary">Créer la grille</button>
</form>
<?php endif ?>
