<div class="head"><span class="mono small muted">11</span><h1>Administration</h1></div>
<div class="grid g2">
  <a class="card pad" href="/admin/utilisateurs"><h2>Utilisateurs</h2><div class="mono strong" style="font-size:28px"><?= $users ?></div><div class="small muted">comptes actifs</div></a>
  <a class="card pad" href="/admin/agences"><h2>Agences</h2><div class="mono strong" style="font-size:28px"><?= $agencies ?></div><div class="small muted">sites et ateliers</div></a>
  <a class="card pad" href="/admin/parametres"><h2>Paramètres</h2><div class="small muted">Seuils, relances, TVA, tolérance de caisse. Chaque changement est versionné et motivé.</div></a>
  <a class="card pad" href="/admin/audit"><h2>Journal d'audit</h2><div class="mono strong" style="font-size:28px"><?= $audit ?></div><div class="small muted">événements, chaîne de hachage vérifiable</div></a>
  <a class="card pad" href="/admin/parcours"><h2>Parcours de traitement</h2><div class="small muted">Étapes prévues pour chaque type de traitement (nettoyage complet, repassage seul…).</div></a>
  <a class="card pad" href="/admin/postes"><h2>Postes hors-ligne</h2><div class="small muted">Postes autorisés à recevoir des commandes sans réseau, plages de numéros, commandes rejetées.</div></a>
  <a class="card pad" href="/admin/sauvegardes"><h2>Sauvegardes</h2><div class="mono strong" style="font-size:28px"><?= $backups ?></div><div class="small muted">archives disponibles</div></a>
</div>
