<?= partial('commercial/_nav', []) ?>
<form method="post" action="/commercial/devis" class="card pad form" style="max-width:820px">
  <?= csrf_field() ?>
  <h2>Nouveau devis</h2>
  <div class="row">
    <div class="field"><label>Client *</label><select class="input" name="client_id" required><option value="">— choisir —</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"<?= selected(old('client_id'), $c['id']) ?>><?= e($c['name']) ?> · <?= e($c['phone']) ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Niveau de service</label><select class="input" name="service_level"><?php foreach ($levels as $l): ?><option value="<?= $l->value ?>"><?= e($l->label()) ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Validité (jours)</label><input class="input mono" type="number" name="valid_days" min="1" max="90" value="15"></div>
  </div>
  <table class="t">
    <thead><tr><th>Article</th><th style="width:110px">Quantité</th></tr></thead>
    <tbody>
    <?php for ($n = 0; $n < 8; $n++): ?>
      <tr><td><select class="input" name="lines[<?= $n ?>][article_id]"><option value="">—</option><?php foreach ($articles as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach ?></select></td>
        <td><input class="input mono" type="number" min="1" step="1" name="lines[<?= $n ?>][qty]" value="1"></td></tr>
    <?php endfor ?>
    </tbody>
  </table>
  <div class="field"><label>Remarques</label><input class="input" name="notes" maxlength="255"></div>
  <div class="small muted">Les prix sont ceux de la grille applicable à ce client à ce jour. La commande se crée ensuite normalement à la réception.</div>
  <button class="btn primary">Établir le devis</button>
</form>
