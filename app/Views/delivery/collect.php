<?php use App\Services\ClientService; ?>
<div class="head"><a class="small" href="/livraisons">← Livraisons</a><h1>Demande de collecte à domicile</h1></div>

<form method="post" action="/livraisons/collecte" class="card pad form" style="max-width:640px">
  <?= csrf_field() ?>
  <?php if ($client): ?>
    <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
    <div class="kv"><span><b><?= e($client['name']) ?></b><br><span class="mono small muted"><?= e($client['code']) ?> · <?= e(ClientService::formatPhone($client['phone'])) ?></span></span><a class="small" href="/livraisons/collecte">Changer</a></div>
  <?php else: ?>
    <div class="field"><label>Client (identifié obligatoirement)</label>
      <select class="input" name="client_id" required>
        <option value="">— choisir —</option>
        <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> · <?= e(ClientService::formatPhone($c['phone'])) ?></option><?php endforeach ?>
      </select>
      <span class="small muted">Client absent de la liste ? <a href="/clients/nouveau">Créez sa fiche</a>, puis revenez ici depuis sa fiche.</span></div>
  <?php endif ?>
  <div class="field"><label>Adresse de collecte</label><input class="input" name="address" value="<?= e(old('address')) ?>" required placeholder="Quartier, rue, repère"></div>
  <div class="field"><label>Créneau</label><input class="input" type="datetime-local" name="slot" value="<?= e(old('slot')) ?>" required></div>
  <div class="field"><label>Précisions</label><input class="input" name="notes" value="<?= e(old('notes')) ?>" placeholder="Nombre de pièces, étage, personne à demander…"></div>
  <button class="btn primary lg">Enregistrer la demande</button>
</form>
