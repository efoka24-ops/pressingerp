<?php $num = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', ' '), '0'), ','); ?>
<div class="head">
  <span class="mono small muted">09</span><h1>Stocks</h1>
  <span class="small muted"><?= count($items) ?> références · <span class="<?= $low ? 'red' : '' ?>"><?= $low ?> sous le minimum</span></span>
</div>

<div class="card scroll">
  <table class="t">
    <thead><tr><th>Article</th><th style="width:240px">Niveau</th><th class="num">Stock</th><th class="num">Conso / jour</th><th class="num">Autonomie</th><th>Fournisseur</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $it):
      $q = (float)$it['quantity']; $min = (float)$it['min_qty']; $use = (float)$it['daily_usage'];
      $days = $use > 0 ? $q / $use : null;
      $ref = max($min * 2, $q, 1);
      $tone = $q < $min ? 'red' : ($days !== null && $days < 10 ? 'orange' : ''); ?>
      <tr class="<?= $tone === 'red' ? 'alert' : ($tone === 'orange' ? 'warn' : '') ?>">
        <td class="strong"><?= e($it['name']) ?></td>
        <td><div class="bar" style="position:relative;overflow:visible"><i class="<?= $tone ?>" style="width:<?= min(100, (int)round($q * 100 / $ref)) ?>%"></i><span style="position:absolute;left:<?= min(100, (int)round($min * 100 / $ref)) ?>%;top:-3px;width:1px;height:12px;background:var(--ink)" title="Seuil minimum"></span></div></td>
        <td class="num"><?= $num($q) ?> <?= e($it['unit']) ?></td>
        <td class="num"><?= $num($use) ?></td>
        <td class="num <?= $tone ?> <?= $tone ? 'strong' : '' ?>"><?= $days === null ? '—' : (int)floor($days) . ' j' ?></td>
        <td><?= e($it['supplier'] ?? '—') ?></td>
        <td>
          <?php if ($it['pending']): ?><span class="small muted">commande en cours</span>
          <?php elseif ($tone): ?>
            <form method="post" action="/stocks/commande" class="row" style="gap:6px;flex-wrap:nowrap"><?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $it['id'] ?>"><input class="input mono" name="qty" type="number" min="0.1" step="0.1" value="<?= $num(max($min * 2 - $q, $use * 20)) ?>" style="width:90px;height:30px"><button class="btn sm primary">Commander</button></form>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <div class="small muted" style="padding:10px 18px">Trait vertical : seuil minimum.</div>
</div>

<div class="grid g2">
  <form method="post" action="/stocks/mouvement" class="card pad form">
    <?= csrf_field() ?>
    <h2>Mouvement de stock</h2>
    <div class="row">
      <div class="field"><label>Article</label><select class="input" name="item_id"><?php foreach ($items as $it): ?><option value="<?= $it['id'] ?>"><?= e($it['name']) ?> (<?= e($it['unit']) ?>)</option><?php endforeach ?></select></div>
      <div class="field"><label>Type</label><select class="input" name="type"><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach ?></select></div>
    </div>
    <div class="row">
      <div class="field" style="max-width:140px"><label>Quantité</label><input class="input mono" name="qty" type="number" min="0" step="0.1" required></div>
      <div class="field"><label>Note</label><input class="input" name="note"></div>
      <button class="btn dark">Enregistrer</button>
    </div>
  </form>

  <div class="card pad">
    <div class="card-h"><h2>Commandes fournisseurs en attente</h2></div>
    <?php if (!$orders): ?><span class="small muted">Aucune.</span><?php endif ?>
    <?php foreach ($orders as $po): ?>
      <div class="kv"><span><?= e($po['item']) ?> · <?= $num($po['qty']) ?> <?= e($po['unit']) ?><br><small><?= e($po['supplier'] ?? '') ?> · le <?= dt($po['created_at'], 'd/m') ?></small></span>
        <form method="post" action="/stocks/commande/<?= $po['id'] ?>/reception"><?= csrf_field() ?><button class="btn sm">Réceptionner</button></form></div>
    <?php endforeach ?>
  </div>
</div>

<div class="grid g2">
  <div class="card scroll">
    <div class="card-h"><h2>Derniers mouvements</h2></div>
    <table class="t"><tbody>
      <?php foreach ($movements as $m): ?>
        <tr><td class="mono muted nowrap"><?= dt($m['created_at'], 'd/m H:i') ?></td><td><?= e($m['item']) ?></td><td class="num <?= $m['qty'] < 0 ? 'red' : 'green' ?>"><?= ($m['qty'] > 0 ? '+' : '') . $num($m['qty']) ?> <?= e($m['unit']) ?></td><td class="small muted"><?= e($m['note'] ?? '') ?> · <?= e($m['user'] ?? '') ?></td></tr>
      <?php endforeach ?>
    </tbody></table>
  </div>
  <form method="post" action="/stocks/articles" class="card pad form">
    <?= csrf_field() ?>
    <h2>Nouvelle référence</h2>
    <div class="row"><div class="field"><label>Désignation</label><input class="input" name="name" required></div><div class="field" style="max-width:110px"><label>Unité</label><input class="input" name="unit" placeholder="L, kg, pcs" required></div></div>
    <div class="row">
      <div class="field"><label>Stock initial</label><input class="input mono" name="quantity" type="number" min="0" step="0.1" value="0"></div>
      <div class="field"><label>Minimum</label><input class="input mono" name="min_qty" type="number" min="0" step="0.1" value="0"></div>
      <div class="field"><label>Conso / jour</label><input class="input mono" name="daily_usage" type="number" min="0" step="0.1" value="0"></div>
    </div>
    <div class="row"><div class="field"><label>Fournisseur</label><input class="input" name="supplier"></div><button class="btn">Ajouter</button></div>
  </form>
</div>
