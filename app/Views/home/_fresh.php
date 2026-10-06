<?php
// Fraîcheur des données (SE22) : à quel moment les chiffres affichés ont été calculés, et la dernière activité connue
$hm = fn(?string $t) => $t ? (substr($t, 0, 10) === date('Y-m-d') ? dt($t, 'H:i') : dt($t, 'd/m H:i')) : 'aucune';
?>
<div class="small muted" id="freshness" style="margin:0 0 10px">
  Données calculées à <b><?= dt($fresh['computed_at'], 'H:i:s') ?></b>
  · dernière commande : <?= e($hm($fresh['last_order'])) ?>
  · dernier paiement : <?= e($hm($fresh['last_payment'])) ?>
  · dernière synchronisation hors-ligne : <?= e($hm($fresh['last_sync'])) ?>
  <?php if ($fresh['rejected'] > 0): ?>· <span class="red"><?= (int)$fresh['rejected'] ?> commande(s) hors-ligne rejetée(s) à traiter : les chiffres peuvent être incomplets</span><?php endif ?>
  · <a href="">Actualiser</a>
</div>
