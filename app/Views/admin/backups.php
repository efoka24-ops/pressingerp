<div class="head"><span class="mono small muted">11</span><h1>Sauvegardes</h1><span class="small muted">Quotidiennes par cron (bin/backup.php), conservées selon la rétention.</span></div>
<div class="card scroll"><table class="t"><thead><tr><th>Fichier</th><th class="num">Taille</th><th>Créé le</th></tr></thead><tbody>
<?php foreach ($files as $f): ?><tr><td class="mono"><?= e($f['name']) ?></td><td class="num"><?= number_format($f['size'] / 1024, 0, ',', ' ') ?> Ko</td><td class="mono"><?= date('d/m/Y H:i', $f['at']) ?></td></tr><?php endforeach ?>
<?php if (!$files): ?><tr><td colspan="3" class="muted">Aucune sauvegarde pour l'instant.</td></tr><?php endif ?>
</tbody></table></div>
<div class="small muted">Les archives ne sont pas téléchargeables depuis le web (dossier interdit). Récupération par FTP ou copie externe.</div>
