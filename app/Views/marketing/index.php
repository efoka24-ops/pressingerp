<div class="head"><span class="mono small muted">08</span><h1>Marketing &amp; Fidélité</h1><span class="small muted"><?= $queue ?> message(s) en file d'envoi</span></div>

<?php if (!$validated): ?>
  <div class="flash warn">Seuils de segmentation <b>à vérifier</b> : nouveau &lt; <?= (int)$th['new_days'] ?> j · à risque dès <?= (int)$th['at_risk_days'] ?> j · perdu au-delà de <?= (int)$th['lost_days'] ?> j · VIP dès <?= money($th['vip']) ?> FCFA/an · régulier dès <?= (int)$th['regular_orders'] ?> commandes/an · fort panier dès <?= money($th['basket_high']) ?>.
    Ces valeurs sont provisoires, la direction doit les confirmer<?php if (can('admin', 'update')): ?> (<a href="/admin/parametres">Paramètres &gt; Segmentation</a>, puis cocher « validés »)<?php endif ?>.</div>
<?php endif ?>
<div class="split left">
  <div class="stack">
    <div class="card pad">
      <div class="card-h"><h2>Segments</h2></div>
      <?php foreach ($labels as $k => $l): if ($k === 'tous') continue; ?>
        <div class="kv <?= $k === 'a_risque' ? 'red' : '' ?>"><span style="<?= $k === 'a_risque' ? 'color:var(--red)' : '' ?>"><?= e($l) ?></span><span class="mono"><?= money($segments[$k] ?? 0) ?></span></div>
      <?php endforeach ?>
      <div class="kv total"><span>Total</span><span class="mono"><?= money($segments['tous'] ?? 0) ?></span></div>
    </div>
    <div class="card pad">
      <div class="card-h"><h2>Programme fidélité</h2></div>
      <div class="kv"><span>1 point</span><span>= <?= (int)$loyalty['fcfa_per_point'] ?> FCFA dépensés</span></div>
      <div class="kv"><span><?= (int)$loyalty['every_nth_order'] ?>e commande</span><span>−<?= (int)$loyalty['nth_discount_pct'] ?> %</span></div>
      <div class="kv"><span>Parrainage</span><span><?= money($loyalty['referral_bonus'], true) ?></span></div>
      <div class="kv"><span>Parrainages (90 j)</span><span class="mono"><?= $referrals ?></span></div>
      <div class="kv total"><span>Points en circulation</span><span class="mono"><?= money($points) ?></span></div>
    </div>
  </div>

  <div class="stack">
    <div class="card scroll">
      <div class="card-h"><h2>Campagnes</h2></div>
      <?php if (!$campaigns): ?><div class="empty">Aucune campagne.</div><?php else: ?>
      <table class="t">
        <thead><tr><th>Campagne</th><th>Cible</th><th>Canal</th><th class="num">Envois</th><th class="num">Retours (14 j)</th><th class="num">CA généré</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach ($campaigns as $c): ?>
          <tr>
            <td><b class="strong"><?= e($c['name']) ?></b><div class="small muted"><?= e(mb_strimwidth($c['message'], 0, 80, '…')) ?></div></td>
            <td><?= e($labels[$c['segment']] ?? $c['segment']) ?></td>
            <td><?= e($channels[$c['channel']] ?? $c['channel']) ?></td>
            <td class="num"><?= $c['sent_count'] ? money($c['sent_count']) : '—' ?></td>
            <td class="num"><?= $c['results'] ? $c['results']['clients'] . ' · ' . pct((float)$c['results']['clients'], (float)$c['sent_count']) . ' %' : '—' ?></td>
            <td class="num"><?= $c['results'] ? money($c['results']['revenue']) : '—' ?></td>
            <td>
              <?php if ($c['status'] === 'envoyee'): ?><span class="muted">Envoyée <?= dt($c['sent_at'], 'd/m') ?></span>
              <?php else: ?>
                <form method="post" action="/marketing/campagnes/<?= $c['id'] ?>/envoyer" data-confirm="Envoyer à <?= (int)($segments[$c['segment']] ?? 0) ?> clients ?"><?= csrf_field() ?><button class="btn sm primary">Envoyer (<?= (int)($segments[$c['segment']] ?? 0) ?>)</button></form>
                <?php if ($c['scheduled_at']): ?><span class="small muted">prévue <?= dt($c['scheduled_at'], 'd/m') ?></span><?php endif ?>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </div>

    <div class="card pad">
      <div class="card-h"><h2>Scénarios automatiques</h2><span class="small muted">évalués chaque jour · uniquement vers les clients consentants</span></div>
      <?php foreach ($scenarios as $sc): $canEdit = can('marketing', 'update'); ?>
        <<?= $canEdit ? 'form method="post" action="/marketing/scenarios/' . e($sc['code']) . '"' : 'div' ?> class="form" style="padding:10px 0;border-top:1px solid var(--line2)"><?= $canEdit ? csrf_field() : '' ?>
          <div class="kv"><span><b><?= e($sc['label']) ?></b><br><span class="small muted">cible : <?= e($labels[$sc['segment']] ?? $sc['segment']) ?> (<?= (int)($segments[$sc['segment']] ?? 0) ?> clients)</span></span>
            <label class="check"><input type="checkbox" name="active" value="1"<?= checked((bool)$sc['active']) ?><?= can('marketing', 'update') ? '' : ' disabled' ?>> Actif</label></div>
          <div class="field"><textarea class="input" name="body" rows="2"<?= can('marketing', 'update') ? '' : ' readonly' ?>><?= e($sc['body']) ?></textarea></div>
          <div class="row" style="align-items:end"><div class="field"><label>Pas deux fois le même client avant (jours)</label><input class="input mono" type="number" name="cooldown_days" min="7" max="730" value="<?= (int)$sc['cooldown_days'] ?>" style="width:110px"></div>
            <?php if ($canEdit): ?><button class="btn sm">Enregistrer</button><?php endif ?></div>
        </<?= $canEdit ? 'form' : 'div' ?>>
      <?php endforeach ?>
    </div>

    <?php if (can('marketing', 'create')): ?>
    <form method="post" action="/marketing/campagnes" class="card pad form">
      <?= csrf_field() ?>
      <h2>Nouvelle campagne</h2>
      <div class="row">
        <div class="field"><label>Nom *</label><input class="input" name="name" required placeholder="Réactivation −20 % 7 jours"></div>
        <div class="field"><label>Cible</label><select class="input" name="segment"><?php foreach ($labels as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?> (<?= (int)($segments[$k] ?? 0) ?>)</option><?php endforeach ?></select></div>
        <div class="field"><label>Canal</label><select class="input" name="channel"><?php foreach ($channels as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach ?></select></div>
      </div>
      <div class="field"><label>Message * <span class="muted">— variables : {prenom}, {nom}, {points}</span></label><textarea class="input" name="message" maxlength="640" required>Bonjour {prenom}, -20 % sur votre prochain dépôt cette semaine chez Pressing. Vous avez {points} points fidélité.</textarea></div>
      <div class="row"><div class="field" style="max-width:240px"><label>Planifier (optionnel)</label><input class="input" type="datetime-local" name="scheduled_at"></div><button class="btn dark">Créer la campagne</button></div>
    </form>
    <?php endif ?>
  </div>
</div>
