<?php use App\Core\Auth;
use App\Domain\PaymentMethod; ?>
<div class="head">
  <span class="mono small muted">06</span><h1>Caisse &amp; Finance</h1>
  <div class="actions"><?php if ($session): ?><a class="btn dark" href="/caisse/cloture">Clôturer ma caisse</a><?php endif ?></div>
</div>

<div class="split">
  <div class="stack">
    <?php if (!$session): ?>
      <form method="post" action="/caisse/ouvrir" class="card pad form" style="max-width:520px">
        <?= csrf_field() ?>
        <h2>Ouvrir ma caisse</h2>
        <div class="row">
          <div class="field"><label>Poste</label><input class="input" name="label" value="Caisse 1"></div>
          <div class="field"><label>Fond de caisse (espèces)</label><input class="input mono" name="opening_float" type="number" min="0" step="500" value="20000"></div>
        </div>
        <button class="btn primary">Ouvrir</button>
      </form>
    <?php else: ?>
      <div class="card pad">
        <div class="card-h"><h2><?= e($session['label']) ?> · ouverte à <?= dt($session['opened_at'], 'H:i') ?></h2><span class="small muted">fond <?= money($session['opening_float']) ?></span></div>
        <div class="grid g3">
          <?php foreach ($methods as $m): ?>
            <div class="kv" style="flex-direction:column;gap:2px"><span class="small"><?= e($m->label()) ?></span><span class="mono strong" style="font-size:17px"><?= money($expected[$m->value]) ?></span></div>
          <?php endforeach ?>
        </div>
        <div class="kv total"><span>Total théorique</span><span class="mono"><?= money(array_sum($expected)) ?></span></div>
      </div>

      <div class="card scroll">
        <div class="card-h"><h2>Encaissements de la session</h2><span class="small muted"><?= count($payments) ?></span></div>
        <?php if (!$payments): ?><div class="empty">Aucun encaissement.</div><?php else: ?>
        <table class="t"><tbody>
          <?php foreach ($payments as $p): ?>
            <tr><td class="mono muted"><?= dt($p['created_at'], 'H:i') ?></td><td class="mono"><?= $p['order_id'] ? '<a href="/commandes/' . $p['order_id'] . '">' . e($p['number']) . '</a>' : '—' ?></td><td><?= e($p['client']) ?></td><td><?= e(PaymentMethod::from($p['method'])->label()) ?><?= $p['reference'] ? ' <span class="mono small muted">' . e($p['reference']) . '</span>' : '' ?></td><td class="num"><?= money($p['amount']) ?></td></tr>
          <?php endforeach ?>
        </tbody></table>
        <?php endif ?>
      </div>

      <form method="post" action="/caisse/depenses" class="card pad form">
        <?= csrf_field() ?>
        <h2>Sortie d'espèces / dépense</h2>
        <div class="row">
          <div class="field"><label>Libellé</label><input class="input" name="label" placeholder="Achat cintres, transport…" required></div>
          <div class="field" style="max-width:180px"><label>Montant</label><input class="input mono" name="amount" type="number" min="1" required></div>
          <button class="btn">Enregistrer</button>
        </div>
        <?php foreach ($expenses as $x): ?><div class="kv small"><span><?= dt($x['created_at'], 'H:i') ?> · <?= e($x['label']) ?></span><span class="mono red">−<?= money($x['amount']) ?></span></div><?php endforeach ?>
      </form>
    <?php endif ?>
  </div>

  <div class="stack">
    <div class="card pad">
      <div class="card-h"><h2>Caisses du jour</h2></div>
      <?php if (!$sessions): ?><span class="small muted">Aucune caisse ouverte aujourd'hui.</span><?php endif ?>
      <?php foreach ($sessions as $cs): ?>
        <div class="kv">
          <span><?= e($cs['agency']) ?> · <?= e($cs['label']) ?><br><small><?= e($cs['user']) ?> · <?= money($cs['cashed']) ?> encaissés<?php if ((int)$cs['user_id'] === Auth::id() || Auth::can('cash', 'validate')): ?> · <a href="/caisse/<?= (int)$cs['id'] ?>/etat">État de caisse</a><?php endif ?></small></span>
          <?php if ($cs['status'] === 'ouverte'): ?><span class="muted">Ouverte<?php if ((int)$cs['user_id'] !== Auth::id() && Auth::can('cash', 'validate')): ?> · <a href="/caisse/<?= (int)$cs['id'] ?>/cloture">Clôturer</a><?php endif ?></span>
          <?php elseif ((int)$cs['diff'] !== 0): ?><span class="red" title="<?= e($cs['justification']) ?>">Écart <?= money($cs['diff']) ?></span>
          <?php else: ?><span class="green">Clôturée · 0</span><?php endif ?>
        </div>
      <?php endforeach ?>
    </div>
    <div class="card pad">
      <div class="card-h"><h2>Mois en cours</h2></div>
      <div class="kv"><span>Recettes</span><span class="mono"><?= money($month['income']) ?></span></div>
      <div class="kv"><span>Dépenses</span><span class="mono">−<?= money($month['expenses']) ?></span></div>
      <div class="kv total"><span>Solde de trésorerie</span><span class="mono"><?= money($month['income'] - $month['expenses']) ?></span></div>
      <?php foreach ($month['byMethod'] as $bm): ?><div class="kv small"><span><?= e(PaymentMethod::from($bm['method'])->label()) ?></span><span class="mono"><?= money($bm['total']) ?> · <?= pct((float)$bm['total'], (float)$month['income']) ?> %</span></div><?php endforeach ?>
    </div>
  </div>
</div>
