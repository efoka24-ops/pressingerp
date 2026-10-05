<div class="head"><a class="small" href="/commandes">← Commandes</a><h1>Commandes non retirées</h1>
  <span class="small muted">Relances automatiques à J+<?= e(implode(', J+', $levels)) ?> · alerte du responsable à J+<?= (int)App\Services\SettingsService::get('reminder.manager_after', 15) ?></span></div>

<div class="grid g4">
<?php foreach ($buckets as $b): ?>
  <div class="card kpi"><span class="l"><?= e($b['label']) ?></span><span class="v"><?= (int)$b['n'] ?></span>
    <span class="small muted"><?= money($b['value']) ?> FCFA de commandes<?= $b['due'] > 0 ? ' · ' . money($b['due']) . ' à encaisser' : '' ?></span></div>
<?php endforeach ?>
</div>

<div class="card scroll">
  <table class="t">
    <thead><tr><th>Commande</th><th>Client</th><th>Prête depuis</th><th class="num">Total</th><th class="num">À encaisser</th><th>Dernière relance</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr class="<?= $o['days'] > 15 ? 'alert' : ($o['days'] > 7 ? 'warn' : '') ?>">
        <td class="mono"><a class="row-link" href="/commandes/<?= (int)$o['id'] ?>"><?= e($o['number']) ?></a></td>
        <td><?= e($o['client']) ?><div class="mono small muted"><?= e($o['phone']) ?></div></td>
        <td><?= (int)$o['days'] ?> jour(s)</td>
        <td class="num"><?= money($o['total']) ?></td>
        <td class="num"><?= $o['due'] > 0 ? money($o['due']) : '—' ?></td>
        <td class="small"><?= $o['last_level'] ? 'Relance n° ' . (int)$o['last_level'] : '<span class="muted">aucune</span>' ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$orders): ?><tr><td colspan="6" class="muted">Aucune commande prête en attente de retrait.</td></tr><?php endif ?>
    </tbody>
  </table>
</div>
