<?php use App\Domain\Step; ?>
<div class="head"><a class="small" href="/qualite">← Qualité</a><h1>Contrôle qualité</h1><span class="small muted"><?= $queueLeft ?> en file</span></div>

<form method="post" action="/qualite/controle/<?= $g['id'] ?>" class="split left" id="qc">
  <?= csrf_field() ?>
  <div class="stack">
    <div class="photo"><?php if ($g['photo_path']): ?><img src="<?= e($g['photo_path']) ?>" alt="Photo à la réception"><?php else: ?>pas de photo de réception<?php endif ?></div>
    <div class="mono strong"><?= e($g['code']) ?></div>
    <div><?= e($g['label']) ?><?= $g['color'] ? ' · ' . e($g['color']) : '' ?><?= $g['material'] ? ' · ' . e($g['material']) : '' ?> · <?= e($g['client']) ?></div>
    <?php if ($g['damages']): ?><div class="note">Réception : <?= e($g['damages']) ?></div><?php endif ?>
    <?php if ($g['preferences']): ?><div class="note">Préférences client : <?= e($g['preferences']) ?></div><?php endif ?>
    <?php if ($g['rework_count']): ?><div class="note err">Déjà reprise <?= (int)$g['rework_count'] ?> fois.</div><?php endif ?>
  </div>

  <div class="stack">
    <div class="field"><span class="label">Touchez un critère pour le marquer non conforme</span>
      <div class="grid g3">
        <?php foreach ($criteria as $k => $label): ?>
          <label class="tile qc-crit" style="flex-direction:row;align-items:center;justify-content:space-between;min-height:52px">
            <input type="checkbox" name="ko[<?= $k ?>]" value="1" hidden>
            <b><?= e($label) ?></b><span class="ok green strong" style="font-family:var(--sans);font-size:14px">✓</span>
          </label>
        <?php endforeach ?>
      </div>
    </div>

    <div class="card pad form" id="rework" hidden>
      <span class="label strong">Motif de reprise <span class="red">*</span></span>
      <div class="chips">
        <?php foreach ($reasons as $i => $r): ?><label class="chip"><input type="radio" name="reason" value="<?= e($r) ?>"><?= e($r) ?></label><?php endforeach ?>
      </div>
      <div class="field"><label>Renvoyer vers</label>
        <select class="input" name="back_to" id="back_to"><?php foreach ($targets as $t): ?><option value="<?= $t->value ?>"><?= e($t->label()) ?></option><?php endforeach ?></select>
      </div>
    </div>

    <div class="grid g2">
      <button class="btn xl" name="result" value="ok" id="btn-ok">Conforme → Emballage</button>
      <button class="btn xl" name="result" value="ko" id="btn-ko" disabled>À reprendre</button>
    </div>
  </div>
</form>

<script>
(function () {
  var map = { detachage: 'detachage', proprete: 'lavage', odeur: 'lavage', repassage: 'repassage', pliage: 'finition', boutons: 'finition', fermetures: 'finition' };
  var crits = document.querySelectorAll('.qc-crit'), rework = document.getElementById('rework');
  var ok = document.getElementById('btn-ok'), ko = document.getElementById('btn-ko'), back = document.getElementById('back_to');
  function sync() {
    var failed = [];
    crits.forEach(function (c) {
      var inp = c.querySelector('input'), mark = c.querySelector('.ok');
      c.style.background = inp.checked ? 'var(--red-soft)' : '';
      c.style.borderColor = inp.checked ? 'var(--red)' : '';
      mark.textContent = inp.checked ? '✕' : '✓';
      mark.className = 'ok strong ' + (inp.checked ? 'red' : 'green');
      if (inp.checked) failed.push(inp.name.replace(/^ko\[|\]$/g, ''));
    });
    rework.hidden = !failed.length;
    ok.disabled = failed.length > 0;
    ko.disabled = !failed.length;
    ko.className = 'btn xl' + (failed.length ? ' danger' : '');
    if (failed.length && map[failed[0]]) back.value = map[failed[0]];
    ko.textContent = 'À reprendre → ' + back.options[back.selectedIndex].text;
  }
  crits.forEach(function (c) { c.addEventListener('click', function (e) { e.preventDefault(); var i = c.querySelector('input'); i.checked = !i.checked; sync(); }); });
  back.addEventListener('change', sync);
  document.getElementById('qc').addEventListener('submit', function (e) {
    if (!rework.hidden && !document.querySelector('input[name=reason]:checked')) { e.preventDefault(); alert('Choisissez un motif de reprise.'); }
  });
  sync();
})();
</script>
