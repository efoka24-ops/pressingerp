<div class="head"><span class="mono small muted">02</span><h1>Réimprimer les étiquettes</h1><span class="mono"><?= e($o['number']) ?></span></div>
<form method="get" action="/commandes/<?= (int)$o['id'] ?>/etiquettes" class="card pad form" style="max-width:520px">
  <p>Les étiquettes de cette commande ont déjà été imprimées. Une réimpression est enregistrée dans le journal : indiquez le motif.</p>
  <div class="field"><label>Motif</label><input class="input" name="motif" required autofocus placeholder="ex. étiquette abîmée, vêtement renvoyé au lavage"></div>
  <button class="btn primary">Réimprimer</button>
  <a class="small" href="/commandes/<?= (int)$o['id'] ?>/etiquettes?manuel=1" target="_blank">Imprimante en panne ? Afficher la liste des codes à écrire à la main</a>
</form>
