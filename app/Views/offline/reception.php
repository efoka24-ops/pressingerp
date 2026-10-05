<?php
/** Page autonome mise en cache : aucune donnée métier ni nom d'utilisateur ici, tout vient de la base locale du poste. */
$v = fn(string $f) => '/assets/' . $f . '?v=' . (is_file(BASE_PATH . '/public/assets/' . $f) ? filemtime(BASE_PATH . '/public/assets/' . $f) : 0);
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Réception hors-ligne · Pressing ERP</title>
<link rel="stylesheet" href="<?= e($v('app.css')) ?>">
<style>
  body { background: var(--bg, #f4f5f4); padding: 14px; }
  .wrap { max-width: 1180px; margin: 0 auto; }
  .net { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; }
  .net i { width: 10px; height: 10px; border-radius: 50%; background: #c0392b; display: inline-block; }
  .net.on i { background: #2e9e5b; }
  .grid2 { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 14px; align-items: start; }
  @media (max-width: 900px) { .grid2 { grid-template-columns: 1fr; } }
  .st-pending { color: #b36b00; } .st-synced { color: #2e7d32; } .st-rejected { color: #c0392b; font-weight: 600; }
  .tiles { display: flex; flex-wrap: wrap; gap: 8px; }
  .flash { margin: 8px 0; }
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>Réception hors-ligne</h1>
    <span class="net" id="net"><i></i><span id="net-label">…</span></span>
    <span class="small muted" id="who"></span>
    <div class="actions">
      <button class="btn" id="btn-sync" type="button">Synchroniser <span id="pending-count"></span></button>
      <button class="btn" id="btn-refresh" type="button">Actualiser les données</button>
      <a class="btn" href="/commandes">Retour au site</a>
    </div>
  </div>
  <div class="flash warn" id="http-warning" hidden><b>Site sans HTTPS.</b> Le mode hors-ligne fonctionne tant que cette page reste <b>ouverte</b>. Si la connexion coupe, ne l'actualisez pas (F5) et ne la fermez pas : le navigateur ne pourrait plus la recharger. Les commandes saisies restent enregistrées sur cet appareil.</div>
  <div id="msg"></div>

  <div class="card pad form" id="setup" hidden>
    <h2>Autoriser ce poste</h2>
    <p>Ce poste n'est pas encore autorisé à recevoir des commandes sans réseau. Un <b>responsable</b> doit être connecté, avec le réseau, pour l'autoriser. Les numéros de commande sont réservés par poste : deux postes ne peuvent jamais produire le même numéro.</p>
    <div class="row"><div class="field"><label>Nom du poste</label><input class="input" id="station-label" placeholder="ex. Comptoir Akwa — tablette 1"></div><button class="btn primary" id="btn-register" type="button">Autoriser ce poste</button></div>
  </div>

  <div id="app" hidden>
    <div class="grid2">
      <div class="stack">
        <div class="card pad form">
          <div id="client-picked" hidden style="display:flex;align-items:center;gap:12px"><div style="flex:1"><b id="cp-name"></b> <span class="mono small muted" id="cp-meta"></span><div class="small muted" id="cp-pref"></div></div><button type="button" class="btn sm" id="client-change">Changer</button></div>
          <div id="client-search-box">
            <div class="field"><label>Client (nom, téléphone ou code)</label><input class="input lg" id="client-search" autocomplete="off" placeholder="Téléphone ou nom du client…"></div>
            <div id="client-results" class="stack" style="gap:4px"></div>
            <details style="margin-top:8px"><summary class="small">Nouveau client (nom complet et téléphone obligatoires)</summary>
              <div class="row" style="margin-top:8px;flex-wrap:wrap;gap:8px">
                <div class="field"><label>Nom et prénom</label><input class="input" id="nc-name"></div>
                <div class="field"><label>Téléphone</label><input class="input mono" id="nc-phone" inputmode="tel" placeholder="6 70 12 34 56"></div>
                <div class="field"><label>Type</label><select class="input" id="nc-type"><option value="particulier">Particulier</option><option value="pro">Entreprise</option></select></div>
                <button type="button" class="btn dark" id="nc-add">Utiliser ce client</button>
              </div>
            </details>
          </div>
        </div>

        <div class="field"><span class="label">Ajouter un article</span><div class="tiles" id="tiles"></div></div>
        <div class="card scroll">
          <table class="t" id="lines"><thead><tr><th>#</th><th>Article</th><th>Qté / m²</th><th>Traitement</th><th>Marque</th><th>Couleur</th><th>Matière</th><th>Taches / dommages</th><th>Photo</th><th></th></tr></thead><tbody></tbody></table>
          <div class="empty" id="lines-empty">Touchez un article ci-dessus pour l'ajouter.</div>
        </div>
      </div>

      <aside class="stack">
        <div class="card pad form">
          <div class="field"><span class="label">Niveau de service</span><div class="seg" id="levels"></div></div>
          <div class="kv"><span>Date promise</span><span class="mono" id="q-promised">—</span></div>
          <label class="check"><input type="checkbox" id="delivery"> Livraison à domicile</label>
          <div class="field" id="delivery-box" hidden><label>Adresse de livraison</label><input class="input" id="delivery-address"></div>
          <div class="field"><label>Note pour l'atelier</label><textarea class="input" id="notes" rows="2" style="min-height:56px"></textarea></div>
          <div class="small muted">L'encaissement (acompte ou solde) se fait au retrait ou dès le retour du réseau.</div>
        </div>
        <div class="card pad">
          <div class="kv"><span id="q-count">0 pièce</span><span class="mono" id="q-subtotal">0</span></div>
          <div class="kv" id="q-surcharge-row" hidden><span>Majoration</span><span class="mono" id="q-surcharge"></span></div>
          <div class="kv green" id="q-discount-row" hidden><span id="q-discount-label"></span><span class="mono" id="q-discount"></span></div>
          <div class="kv" id="q-delivery-row" hidden><span>Livraison</span><span class="mono" id="q-delivery"></span></div>
          <div class="kv total" style="align-items:baseline"><span>Total TTC</span><span class="mono" style="font-size:24px" id="q-total">0 <small>FCFA</small></span></div>
        </div>
        <button class="btn primary lg block" id="submit" type="button" disabled>Valider la commande</button>
        <div class="small muted center" id="blocker">Sélectionnez un client et au moins un article.</div>
        <div class="small muted center" id="numbers"></div>
      </aside>
    </div>
  </div>

  <div class="card scroll" style="margin-top:14px">
    <div class="card-h"><h2>Commandes de ce poste</h2><span class="small muted" id="queue-hint"></span></div>
    <table class="t"><thead><tr><th>Numéro</th><th>Client</th><th class="num">Total</th><th>Saisie</th><th>État</th><th></th></tr></thead><tbody id="queue"></tbody></table>
  </div>
  <div style="margin-top:14px"><button class="btn sm" id="btn-forget" type="button">Retirer ce poste (efface les données locales)</button></div>
</div>
<script>if (!window.isSecureContext) { document.getElementById('http-warning').hidden = false; }</script>
<script src="<?= e($v('offline-core.js')) ?>"></script>
<script src="<?= e($v('offline.js')) ?>"></script>
</body>
</html>
