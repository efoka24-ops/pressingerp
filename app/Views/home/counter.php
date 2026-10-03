<?php
use App\Core\Auth;
use App\Domain\PaymentMethod;
$u = Auth::user();
$first = explode(' ', $u['name'])[0];
?>
<div class="head">
  <h1>Bonjour <?= e($first) ?></h1>
  <span class="small muted"><?= e($u['agency_name']) ?><?= $session ? ' · ' . e($session['label']) . ' ouverte à ' . dt($session['opened_at'], 'H:i') : ' · caisse fermée' ?></span>
</div>

<div class="split">
  <div class="stack">
    <form action="/recherche"><input class="input lg" name="q" placeholder="Scanner un ticket ou saisir un téléphone, un nom…" autofocus autocomplete="off"></form>
    <div class="big-actions">
      <a class="action" href="/commandes/nouvelle"><kbd>F1</kbd><div><b>Nouvelle commande</b><br><span>Dépôt de vêtements</span></div></a>
      <a class="action dark" href="/commandes?tab=pretes"><kbd><?= $readyN ?> prêtes</kbd><div><b>Retrait client</b><br><span>Restitution + encaissement du solde</span></div></a>
      <a class="action light" href="/clients/nouveau"><b style="font-size:17px">Nouveau client</b><span class="small muted">Particulier ou professionnel</span></a>
      <a class="action light" href="/tracabilite"><b style="font-size:17px">Où est ma pièce ?</b><span class="small muted">Retrouver une pièce par son code</span></a>
    </div>

    <div class="card">
      <div class="card-h"><h2>Retraits attendus aujourd'hui</h2><span class="small muted"><?= $readyN ?> prête<?= $readyN > 1 ? 's' : '' ?><?= $late ? ' · ' . count($late) . ' en retard atelier' : '' ?></span></div>
      <?php if (!$pickups): ?><div class="empty">Aucun retrait attendu.</div><?php else: ?>
      <table class="t">
        <tbody>
        <?php foreach ($pickups as $o): $bal = $o['on_account'] ? 0 : $o['total'] - $o['paid']; ?>
          <tr class="<?= $o['status'] !== 'pret' && strtotime($o['promised_at']) < time() ? 'alert' : '' ?>">
            <td class="mono"><a class="row-link" href="/commandes/<?= $o['id'] ?>"><?= e($o['number']) ?></a></td>
            <td><?= e($o['client']) ?><?= client_tags($o) ?> <span class="muted">· <?= $o['pcs'] ?> pcs</span></td>
            <td class="mono"><?= $o['status'] === 'pret' ? e($o['rail'] ?: '—') : 'Atelier' ?></td>
            <td class="num"><?= $bal > 0 ? money($bal) : '<span class="muted">soldé</span>' ?></td>
            <td class="right">
              <?php if ($o['status'] === 'pret'): ?><span class="badge green">Prête</span>
              <?php elseif (strtotime($o['promised_at']) < time()): ?><span class="badge red">En retard</span>
              <?php else: ?><span class="badge orange"><?= fdate($o['promised_at']) ?></span><?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </div>
  </div>

  <div class="stack">
    <div class="card pad">
      <div class="card-h"><h2>Ma caisse</h2><?php if ($session): ?><a class="small" href="/caisse">Détail</a><?php endif ?></div>
      <?php if ($session): ?>
        <?php foreach (PaymentMethod::counter() as $m): if (!$expected[$m->value] && $m !== PaymentMethod::Especes) continue; ?>
          <div class="kv"><span><?= e($m->label()) ?></span><span class="mono"><?= money($expected[$m->value]) ?></span></div>
        <?php endforeach ?>
        <div class="kv total"><span>Total théorique</span><span class="mono"><?= money(array_sum($expected)) ?></span></div>
      <?php else: ?>
        <form method="post" action="/caisse/ouvrir" class="form">
          <?= csrf_field() ?>
          <div class="row"><div class="field"><label>Fond de caisse</label><input class="input mono" name="opening_float" type="number" min="0" step="500" value="20000"></div><div class="field"><label>Poste</label><input class="input" name="label" value="Caisse 1"></div></div>
          <button class="btn primary block">Ouvrir ma caisse</button>
        </form>
      <?php endif ?>
    </div>
    <div class="card pad">
      <div class="card-h"><h2>Mes chiffres du jour</h2></div>
      <div class="grid g3 center">
        <div><div class="mono strong" style="font-size:20px"><?= (int)$stats['deposits'] ?></div><span class="small muted">dépôts</span></div>
        <div><div class="mono strong" style="font-size:20px"><?= (int)$stats['pickups'] ?></div><span class="small muted">retraits</span></div>
        <div><div class="mono strong" style="font-size:20px"><?= (int)$stats['new_clients'] ?></div><span class="small muted">nouveaux clients</span></div>
      </div>
    </div>
    <?php foreach (array_slice($late, 0, 2) as $o): ?>
      <div class="note warn"><b><?= e($o['client']) ?></b> — commande <?= e($o['number']) ?> promise <?= fdate($o['promised_at']) ?>, toujours en atelier. Prévenir le client<?= $o['is_vip'] ? ' (VIP : proposer la livraison offerte)' : '' ?>.</div>
    <?php endforeach ?>
    <?php if ($session): ?><a class="btn lg block" href="/caisse/cloture">Clôturer ma caisse</a><?php endif ?>
  </div>
</div>
