<div class="head"><a class="small" href="/caisse">← Caisse</a><h1>Clôture · <?= e($session['label']) ?></h1><span class="small muted">ouverte à <?= dt($session['opened_at'], 'H:i') ?> · fond <?= money($session['opening_float']) ?></span></div>

<form method="post" action="<?= e($action ?? '/caisse/cloture') ?>" class="card" style="max-width:820px" id="close-form">
  <?= csrf_field() ?>
  <table class="t" style="font-size:14px">
    <thead><tr><th>Mode</th><th class="num">Théorique</th><th class="num" style="width:200px">Compté / relevé</th><th class="num">Écart</th></tr></thead>
    <tbody>
    <?php foreach ($methods as $m): ?>
      <tr data-exp="<?= (int)$expected[$m->value] ?>">
        <td><?= e($m->label()) ?></td>
        <td class="num"><?= money($expected[$m->value]) ?></td>
        <td class="num"><input class="input mono right cnt" name="counted[<?= $m->value ?>]" type="number" min="0" step="5" value="<?= e(old('counted')[$m->value] ?? '') ?>" placeholder="<?= (int)$expected[$m->value] ?>" required></td>
        <td class="num gap muted">—</td>
      </tr>
    <?php endforeach ?>
      <tr class="strong"><td>Total</td><td class="num"><?= money(array_sum($expected)) ?></td><td class="num" id="sum-cnt">—</td><td class="num" id="sum-gap">—</td></tr>
    </tbody>
  </table>
  <div class="form" style="padding:16px 18px;border-top:1px solid var(--line2)">
    <div class="field"><label>Justification de l'écart <span id="just-req" class="red" hidden>(obligatoire)</span></label><textarea class="input" name="justification" id="just"><?= e(old('justification')) ?></textarea></div>
    <div class="row"><button class="btn dark lg" id="close-btn">Valider la clôture</button><span class="small muted">Les écarts sont transmis à la direction.</span></div>
  </div>
</form>

<script>
(function () {
  var fmt = function (n) { return (n > 0 ? '+' : '') + n.toLocaleString('fr-FR'); };
  function sync() {
    var sc = 0, sg = 0, filled = true;
    document.querySelectorAll('tr[data-exp]').forEach(function (tr) {
      var inp = tr.querySelector('.cnt'), cell = tr.querySelector('.gap');
      if (inp.value === '') { filled = false; cell.textContent = '—'; cell.className = 'num gap muted'; return; }
      var g = parseInt(inp.value, 10) - parseInt(tr.dataset.exp, 10);
      sc += parseInt(inp.value, 10); sg += g;
      cell.textContent = fmt(g); cell.className = 'num gap ' + (g === 0 ? 'muted' : 'red strong');
      tr.className = g === 0 ? '' : 'alert';
    });
    document.getElementById('sum-cnt').textContent = filled ? sc.toLocaleString('fr-FR') : '—';
    document.getElementById('sum-gap').textContent = filled ? fmt(sg) : '—';
    document.getElementById('sum-gap').className = 'num ' + (sg ? 'red' : '');
    var need = Array.from(document.querySelectorAll('.gap')).some(function (c) { return c.classList.contains('red'); });
    document.getElementById('just-req').hidden = !need;
    document.getElementById('just').required = need;
  }
  document.querySelectorAll('.cnt').forEach(function (i) { i.addEventListener('input', sync); });
  sync();
})();
</script>
