<?php use App\Core\Auth; use App\Domain\Role; $w = Auth::can('admin', 'update'); ?>
<div class="head"><span class="mono small muted">11</span><h1>Utilisateurs</h1><span class="small muted"><?= count($users) ?> comptes</span></div>
<div class="card scroll">
  <table class="t">
    <thead><tr><th>Nom</th><th>Identifiant</th><th>Profil</th><th>Agence</th><th>État</th><?php if ($w): ?><th>Modifier (motif obligatoire)</th><?php endif ?></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr class="<?= $u['active'] ? '' : 'warn' ?>">
        <td class="strong"><?= e($u['name']) ?></td><td class="mono"><?= e($u['login']) ?></td>
        <td><?= e(Role::tryFrom($u['role'])?->label() ?? $u['role']) ?></td><td><?= e($u['agency']) ?></td>
        <td><?= $u['active'] ? 'Actif' : 'Désactivé' ?></td>
        <?php if ($w): ?><td>
          <form method="post" action="/admin/utilisateurs/<?= $u['id'] ?>" class="row" style="gap:6px;flex-wrap:wrap"><?= csrf_field() ?>
            <select class="input" name="role"><?php foreach (Role::cases() as $r): ?><option value="<?= $r->value ?>" <?= $r->value === $u['role'] ? 'selected' : '' ?>><?= e($r->label()) ?></option><?php endforeach ?></select>
            <select class="input" name="agency_id"><?php foreach ($agencies as $a): ?><option value="<?= $a['id'] ?>" <?= (int)$a['id'] === (int)$u['agency_id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach ?></select>
            <select class="input" name="active"><option value="1" <?= $u['active'] ? 'selected' : '' ?>>Actif</option><option value="0" <?= $u['active'] ? '' : 'selected' ?>>Désactivé</option></select>
            <input class="input" name="reason" placeholder="Motif" required>
            <button class="btn sm">Enregistrer</button>
          </form>
          <form method="post" action="/admin/utilisateurs/<?= $u['id'] ?>/mot-de-passe" class="row" style="gap:6px;margin-top:6px"><?= csrf_field() ?>
            <input class="input" name="reason" placeholder="Motif de réinitialisation" required><button class="btn sm">Réinitialiser le mot de passe</button>
          </form>
        </td><?php endif ?>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>
<?php if (Auth::can('admin', 'create')): ?>
<form method="post" action="/admin/utilisateurs" class="card pad form"><?= csrf_field() ?>
  <h2>Nouvel utilisateur</h2>
  <div class="row">
    <div class="field"><label>Nom</label><input class="input" name="name" value="<?= e(old('name')) ?>" required></div>
    <div class="field"><label>Identifiant</label><input class="input mono" name="login" value="<?= e(old('login')) ?>" required></div>
    <div class="field"><label>Profil</label><select class="input" name="role"><?php foreach (Role::cases() as $r): ?><option value="<?= $r->value ?>"><?= e($r->label()) ?></option><?php endforeach ?></select></div>
    <div class="field"><label>Agence</label><select class="input" name="agency_id"><?php foreach ($agencies as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach ?></select></div>
  </div>
  <div class="row">
    <div class="field"><label>Mot de passe (vide = généré)</label><input class="input mono" name="password" autocomplete="new-password"></div>
    <div class="field"><label>PIN atelier (optionnel)</label><input class="input mono" name="pin" inputmode="numeric"></div>
  </div>
  <button class="btn primary">Créer</button>
</form>
<?php endif ?>
