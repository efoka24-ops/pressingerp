<div class="card pad form" style="margin-top:24px">
  <span class="mono muted">Erreur <?= (int)$status ?></span>
  <h1><?= e($message) ?></h1>
  <div class="row"><a class="btn" href="javascript:history.back()">← Retour</a><a class="btn dark" href="/">Accueil</a></div>
  <?php if (!empty($trace)): ?><pre style="white-space:pre-wrap;font-size:11px;background:#EFECE5;padding:12px;border-radius:6px;overflow:auto"><?= e($trace) ?></pre><?php endif ?>
</div>
