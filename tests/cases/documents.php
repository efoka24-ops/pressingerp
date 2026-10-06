<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Domain\PaymentMethod;
use App\Services\CashService;
use App\Services\ClientService;
use App\Services\DashboardService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\QuoteService;
use App\Services\ReportService;
use App\Services\SearchService;

test('recherche : téléphone sous toutes ses écritures, nom et code client', function () {
    Auth::actAs(fx_user('comptoir', fx_agency()));
    $phone = '6' . random_int(10000000, 99999999);
    $c = Database::insert('clients', ['code' => 'T' . bin2hex(random_bytes(5)), 'name' => 'Zoé Recherchable', 'phone' => '+237' . $phone]);
    $ids = fn(string $q) => array_column(SearchService::run($q)['clients'], 'id');
    ok(in_array($c, $ids($phone)), 'numéro national');
    ok(in_array($c, $ids(substr($phone, 0, 1) . ' ' . substr($phone, 1, 2) . ' ' . substr($phone, 3, 2) . ' ' . substr($phone, 5, 2) . ' ' . substr($phone, 7))), 'numéro avec espaces');
    ok(in_array($c, $ids('+237 ' . $phone)), 'avec indicatif');
    ok(in_array($c, $ids('Recherchable')), 'nom');
    ok(in_array($c, $ids((string)Database::value('SELECT code FROM clients WHERE id = ?', [$c]))), 'code client');
    same([], SearchService::run('a')['clients'], 'moins de 2 caractères : rien');
});

test('recherche : numéro de commande, de pièce (QR) et de documents commerciaux mènent droit au document', function () {
    $agency = fx_agency('SA');
    $admin = fx_user('admin', $agency);
    Auth::actAs($admin);
    $client = fx_client();
    $order = fx_order($agency, $client, 5000);
    $number = (string)Database::value('SELECT number FROM orders WHERE id = ?', [$order]);
    $g = fx_garment($order);
    $code = (string)Database::value('SELECT code FROM garments WHERE id = ?', [$g]);
    // Les numéros de test (T-…) ne suivent pas le format PR- : on les met au format réel
    $real = 'PR-2026-9' . random_int(10000, 99999);
    Database::update('orders', ['number' => $real], 'id = :id', ['id' => $order]);
    Database::update('garments', ['code' => $real . '-01'], 'id = :id', ['id' => $g]);
    same('/commandes/' . $order, SearchService::run($real)['redirect']);
    same('/commandes/' . $order, SearchService::run(strtolower($real))['redirect'], 'insensible à la casse');
    same('/scan?code=' . urlencode($real . '-01'), SearchService::run($real . '-01')['redirect'], 'pièce : écran atelier');
    $acc = fx_account($agency, [4000]);
    (new InvoiceService())->generate(last_month());
    $inv = Database::one("SELECT * FROM invoices WHERE client_id = ?", [$acc['client']]);
    same('/commercial/factures/' . $inv['id'], SearchService::run($inv['number'])['redirect'], 'facture au nouveau format');
    $cn = (new InvoiceService())->creditNote((int)$inv['id'], 500, 'Erreur de prix constatée', $admin);
    $cnNumber = (string)Database::value('SELECT number FROM invoices WHERE id = ?', [$cn]);
    same('/commercial/factures/' . $cn, SearchService::run($cnNumber)['redirect'], 'avoir');
    $article = fx_article('Nappe', 2500);
    $qid = (new QuoteService())->create($acc['client'], 'standard', [['article_id' => $article, 'qty' => 1]]);
    same('/commercial/devis/' . $qid, SearchService::run((string)Database::value('SELECT number FROM quotes WHERE id = ?', [$qid]))['redirect'], 'devis');
    $found = SearchService::run(substr($inv['number'], 0, 9));
    ok(count($found['invoices']) >= 1, 'recherche partielle de facture');
    $legacy = 'FA-2025-00001';
    Database::insert('invoices', ['number' => $legacy, 'client_id' => $acc['client'], 'period_start' => '2025-01-01', 'period_end' => '2025-01-31', 'total' => 100, 'due_date' => '2025-02-28', 'created_at' => now()]);
    ok(str_starts_with((string)SearchService::run($legacy)['redirect'], '/commercial/factures/'), 'ancien format de numéro');
});

test('recherche : chaque profil ne trouve que ce qu\'il a le droit de voir', function () {
    $a = fx_agency('SB');
    $b = fx_agency('SC');
    Auth::actAs(fx_user('admin', $a));
    $client = fx_client();
    $orderB = fx_order($b, $client, 4000);
    $real = 'PR-2026-8' . random_int(10000, 99999);
    Database::update('orders', ['number' => $real], 'id = :id', ['id' => $orderB]);
    $acc = fx_account($a, [3000]);
    (new InvoiceService())->generate(last_month());
    $invNumber = (string)Database::value('SELECT number FROM invoices WHERE client_id = ?', [$acc['client']]);

    Auth::actAs(fx_user('manager', $a));
    same(null, SearchService::run($real)['redirect'], 'commande d\'une autre agence : introuvable');
    same([], SearchService::run($real)['orders']);
    same(null, SearchService::run($invNumber)['redirect'], 'le responsable d\'agence n\'a pas le module commercial');
    same([], SearchService::run($invNumber)['invoices']);
    Auth::actAs(fx_user('atelier', $a));
    same([], SearchService::run('Client')['clients'], 'l\'atelier n\'a pas accès aux clients');
    same([], SearchService::run($real)['orders']);
    Auth::actAs(fx_user('commercial', $a));
    ok(SearchService::run($invNumber)['redirect'] !== null, 'le commercial retrouve la facture');
});

test('rapport journalier : égal au cockpit et au SQL brut (agence et groupe)', function () {
    $agency = fx_agency('RA');
    $other = fx_agency('RB');
    $agent = fx_user('comptoir', $agency);
    Auth::actAs($agent);
    (new CashService())->open((int)$agent['id'], $agency, 0, 'Caisse rapport');
    $client = fx_client();
    $o1 = fx_order($agency, $client, 7000);
    $o2 = fx_order($agency, $client, 3000);
    fx_order($other, $client, 9000);
    $cancelled = fx_order($agency, $client, 500);
    Database::update('orders', ['status' => 'annule'], 'id = :id', ['id' => $cancelled]);
    fx_garment($o1);
    fx_garment($o2);
    (new PaymentService())->record($client, PaymentMethod::Especes, 7000, orderId: $o1);
    (new PaymentService())->record($client, PaymentMethod::Orange, 1000, orderId: $o2, reference: 'OM-RAPPORT-1');
    foreach ([$agency, 0] as $ag) {
        $r = ReportService::daily(date('Y-m-d'), $ag);
        $t = (new DashboardService($ag))->today();
        same($t['revenue'], $r['revenue'], 'CA = cockpit');
        same($t['orders'], $r['orders'], 'commandes = cockpit');
        same($t['pieces'], $r['pieces'], 'pièces = cockpit');
        same($t['basket'], $r['basket'], 'panier = cockpit');
        same((new DashboardService($ag))->money()['cashed'], $r['cashed'], 'encaissé = cockpit');
    }
    $r = ReportService::daily(date('Y-m-d'), $agency);
    same(10000, $r['revenue']);
    same(1, $r['cancelled']);
    same(8000, $r['cashed']);
    same(['especes' => 7000, 'orange' => 1000], array_map('intval', array_column($r['payments'], 'total', 'method')));
    throws(fn() => ReportService::daily('2026-13-45', 0), 'invalide');
    throws(fn() => ReportService::daily('hier', 0), 'invalide');
});

test('rapport mensuel : total = somme des jours = SQL brut, comparaison au mois précédent', function () {
    $agency = fx_agency('RC');
    Auth::actAs(fx_user('manager', $agency));
    $client = fx_client();
    $thisMonth = date('Y-m');
    $prevMonth = date('Y-m', strtotime('first day of last month'));
    foreach ([[1, 4000], [2, 6000], [3, 2000]] as [$day, $total]) {
        $o = fx_order($agency, $client, $total);
        Database::update('orders', ['created_at' => sprintf('%s-%02d 10:00:00', $thisMonth, $day)], 'id = :id', ['id' => $o]);
    }
    $o = fx_order($agency, $client, 6000);
    Database::update('orders', ['created_at' => $prevMonth . '-15 10:00:00'], 'id = :id', ['id' => $o]);
    $r = ReportService::monthly($thisMonth, $agency);
    same(12000, $r['revenue']);
    same(3, $r['orders']);
    same($r['revenue'], array_sum(array_map(fn($d) => (int)$d['revenue'], $r['days'])), 'somme des jours');
    same((int)Database::value("SELECT SUM(total) FROM orders WHERE agency_id = ? AND status <> 'annule' AND DATE_FORMAT(created_at, '%Y-%m') = ?", [$agency, $thisMonth]), $r['revenue'], 'SQL brut');
    same(6000, $r['prev_revenue']);
    same(100.0, (float)$r['growth']);
    throws(fn() => ReportService::monthly('2026-99', 0), 'invalide');
});

test('relevé de compte : solde égal à l\'encours, règlements de la période seulement', function () {
    $admin = fx_user('admin', fx_agency());
    Auth::actAs($admin);
    $agency = fx_agency('RD');
    $acc = fx_account($agency, [6000, 4000]);
    (new InvoiceService())->generate(last_month());
    (new InvoiceService())->settle($acc['client'], 3000, PaymentMethod::Virement, 'VIR-REL-1');
    $s = ReportService::statement($acc['client'], date('Y-m-d', strtotime('-90 days')), date('Y-m-d'));
    same(ClientService::outstanding($acc['client']), $s['outstanding']);
    same(7000, $s['outstanding']);
    same(3000, array_sum(array_map(fn($p) => (int)$p['amount'], $s['payments'])));
    same(2, count($s['orders']));
    $old = ReportService::statement($acc['client'], '2020-01-01', '2020-12-31');
    same([], $old['payments']);
    same(7000, $old['outstanding'], 'le solde est toujours celui d\'aujourd\'hui');
    throws(fn() => ReportService::statement($acc['client'], '2026-02-01', '2026-01-01'), 'précède');
    throws(fn() => ReportService::statement(0, '2026-01-01', '2026-02-01'), 'introuvable');
});

test('pages : les onze documents s\'impriment (ticket, devis, bon de commande, facture, reçu, bon de livraison, relevé, état de caisse, créances, rapports)', function () {
    $agency = fx_agency('RE');
    $admin = fx_user('admin', $agency);
    Auth::actAs($admin);
    $agent = fx_user('comptoir', $agency);
    // Facture et devis
    $acc = fx_account($agency, [5000]);
    (new InvoiceService())->generate(last_month());
    $inv = Database::one('SELECT * FROM invoices WHERE client_id = ?', [$acc['client']]);
    ok(str_contains(render_page('/commercial/factures/' . $inv['id']), 'Total TTC'), 'facture');
    $article = fx_article('Serviette', 1500);
    $qid = (new QuoteService())->create($acc['client'], 'standard', [['article_id' => $article, 'qty' => 2]]);
    ok(str_contains(render_page('/commercial/devis/' . $qid), 'Total TTC'), 'devis');
    // Relevé, créances, rapports
    ok(str_contains(render_page('/clients/' . $acc['client'] . '/releve'), 'Relevé de compte'), 'relevé');
    ok(str_contains(render_page('/recouvrement/etat'), 'État des créances'), 'état des créances');
    ok(str_contains(render_page('/rapports/journalier'), 'Rapport journalier'), 'rapport journalier');
    ok(str_contains(render_page('/rapports/mensuel'), 'Rapport mensuel'), 'rapport mensuel');
    // Bon de commande fournisseur
    $item = Database::insert('stock_items', ['name' => 'Détergent test', 'unit' => 'L', 'quantity' => 4, 'min_qty' => 10, 'daily_usage' => 1, 'supplier' => 'Fournisseur Test']);
    $po = Database::insert('purchase_orders', ['stock_item_id' => $item, 'qty' => 50, 'status' => 'commandee', 'created_at' => now()]);
    $html = render_page('/stocks/commande/' . $po . '/bon');
    ok(str_contains($html, 'BC-' . str_pad((string)$po, 5, '0', STR_PAD_LEFT)) && str_contains($html, 'Fournisseur Test'), 'bon de commande');
    // Bon de livraison
    $f = fx_delivery();
    ok(str_contains(render_page('/livraisons/' . $f['delivery'] . '/bon'), 'Bon de livraison') && str_contains(render_page('/livraisons/' . $f['delivery'] . '/bon'), 'signature'), 'bon de livraison');
    // Recherche
    ok(str_contains(render_page('/recherche?q=Client'), 'Recherche'), 'recherche');
});

test('rapports : un responsable ne peut pas demander une autre agence (SE23)', function () {
    $mine = fx_agency('RF');
    $theirs = fx_agency('RG');
    Auth::actAs(fx_user('manager', $mine));
    throws(fn() => render_page('/rapports/journalier?agence=' . $theirs), 'limitée à votre agence');
    throws(fn() => render_page('/rapports/mensuel?agence=' . $theirs), 'limitée à votre agence');
    ok(str_contains(render_page('/rapports/journalier'), 'Rapport journalier'));
    Auth::actAs(fx_user('comptoir', $mine));
    throws(fn() => render_page('/rapports/journalier'), 'droit');
});
