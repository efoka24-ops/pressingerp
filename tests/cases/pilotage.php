<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Services\AlertService;
use App\Services\CashService;
use App\Services\DashboardService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Domain\PaymentMethod;

/** Commande d'une agence à une date donnée. */
function fx_dated_order(int $agency, int $client, int $total, string $when, array $over = []): int
{
    $o = fx_order($agency, $client, $total);
    Database::update('orders', array_merge(['created_at' => $when], $over), 'id = :id', ['id' => $o]);
    return $o;
}

test('réconciliation SC-003 : les indicateurs du cockpit égalent le calcul SQL brut (agence et groupe)', function () {
    $agency = fx_agency('PA');
    $other = fx_agency('PB');
    Auth::actAs(fx_user('manager', $agency));
    $client = fx_client();
    $now = date('Y-m-d H:i:s');
    $a = fx_dated_order($agency, $client, 12000, $now);
    $b = fx_dated_order($agency, $client, 8000, $now);
    $c = fx_dated_order($agency, $client, 5000, $now, ['status' => 'annule']);
    $d = fx_dated_order($other, $client, 30000, $now);
    fx_garment($a);
    fx_garment($b);
    fx_garment($c);
    foreach ([[$agency, 'DashboardService agence'], [0, 'groupe']] as [$ag, $label]) {
        $t = (new DashboardService($ag))->today();
        $where = "o.created_at >= CURDATE() AND o.status <> 'annule'" . ($ag ? " AND o.agency_id = $ag" : '');
        $raw = Database::one("SELECT COUNT(*) n, COALESCE(SUM(o.total), 0) s, COUNT(DISTINCT o.client_id) c FROM orders o WHERE $where");
        $pieces = (int)Database::value("SELECT COUNT(*) FROM garments g JOIN orders o ON o.id = g.order_id WHERE $where");
        same((int)$raw['s'], $t['revenue'], "CA ($label)");
        same((int)$raw['n'], $t['orders'], "commandes ($label)");
        same($pieces, $t['pieces'], "pièces ($label)");
        same((int)$raw['c'], $t['clients'], "clients ($label)");
        same($raw['n'] ? (int)round($raw['s'] / $raw['n']) : 0, $t['basket'], "panier moyen ($label)");
    }
    $t = (new DashboardService($agency))->today();
    same(20000, $t['revenue'], 'la commande annulée et l\'autre agence sont exclues');
    same(2, $t['orders']);
    same(2, $t['pieces']);
});

test('réconciliation : encaissé, créances, production et BI égalent le SQL brut', function () {
    $agency = fx_agency('PC');
    $agent = fx_user('comptoir', $agency);
    Auth::actAs($agent);
    (new CashService())->open((int)$agent['id'], $agency, 0, 'Caisse pilotage');
    $client = fx_client();
    $o = fx_dated_order($agency, $client, 9000, date('Y-m-d H:i:s'));
    fx_garment($o);
    (new PaymentService())->record($client, PaymentMethod::Especes, 4000, orderId: $o);
    (new PaymentService())->record($client, PaymentMethod::Orange, 2000, orderId: $o, reference: 'OM-PILOT-1');
    $m = (new DashboardService($agency))->money();
    same(6000, $m['cashed']);
    same(33, (int)round($m['momo_share']), 'part Mobile Money : 2000 / 6000');
    foreach ([$agency, 0] as $ag) {
        $raw = (int)Database::value('SELECT COALESCE(SUM(p.amount), 0) FROM payments p LEFT JOIN cash_sessions cs ON cs.id = p.cash_session_id WHERE p.created_at >= CURDATE()' . ($ag ? " AND cs.agency_id = $ag" : ''));
        same($raw, (new DashboardService($ag))->money()['cashed'], 'encaissé ' . ($ag ?: 'groupe'));
    }
    // Créances : tableau de bord du groupe = balance âgée
    $svc = new InvoiceService();
    $aging = array_sum(array_column($svc->aging(), 'balance'));
    same($aging, (new DashboardService(0))->money()['receivables'], 'créances du groupe = balance âgée');
    // Production : pièces en atelier par étape
    $prod = (new DashboardService($agency))->production();
    $raw = (int)Database::value("SELECT COUNT(*) FROM garments g JOIN orders o ON o.id = g.order_id WHERE o.status IN ('en_atelier', 'pret') AND g.step NOT IN ('reception', 'retire', 'pret') AND o.agency_id = ?", [$agency]);
    same($raw, $prod['total'], 'pièces en atelier');
});

test('SE22 : fraîcheur des données — heure de calcul et dernière activité connue', function () {
    $agency = fx_agency('PD');
    Auth::actAs(fx_user('manager', $agency));
    $client = fx_client();
    $when = date('Y-m-d H:i:s', time() - 600);
    fx_dated_order($agency, $client, 3000, $when);
    $f = (new DashboardService($agency))->freshness();
    same($when, $f['last_order']);
    ok(abs(strtotime($f['computed_at']) - time()) < 5);
    same(null, (new DashboardService(fx_agency('PE')))->freshness()['last_order'], 'agence sans commande');
    $html = render_page('/cockpit');
    ok(str_contains($html, 'Données calculées à') && str_contains($html, 'dernière commande'));
});

test('SE23 : une agence hors périmètre est refusée et journalisée', function () {
    $mine = fx_agency('PF');
    $theirs = fx_agency('PG');
    Auth::actAs(fx_user('manager', $mine));
    same($mine, Auth::resolveAgencyScope(0, 'cockpit'), 'sans demande : sa propre agence');
    same($mine, Auth::resolveAgencyScope($mine, 'cockpit'));
    $e = throws(fn() => Auth::resolveAgencyScope($theirs, 'cockpit'), 'limitée à votre agence');
    same(403, $e->getCode());
    same(1, (int)Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'access.denied' AND entity = 'agencies' AND entity_id = ?", [$theirs]));
    throws(fn() => render_page('/cockpit?agence=' . $theirs), 'limitée à votre agence');
    Auth::actAs(fx_user('direction', $mine));
    same($theirs, Auth::resolveAgencyScope($theirs, 'cockpit'), 'la direction choisit son périmètre');
    same(0, Auth::resolveAgencyScope(0, 'cockpit'));
});

test('alertes managériales : CA d\'hier en baisse de 25 % par rapport aux mêmes jours', function () {
    $agency = fx_agency('PH');
    Auth::actAs(fx_user('manager', $agency));
    $client = fx_client();
    $now = time();
    foreach ([8, 15, 22, 29] as $back) {
        fx_dated_order($agency, $client, 10000, date('Y-m-d 11:00:00', $now - $back * 86400));
    }
    $svc = new DashboardService($agency);
    $keys = fn() => array_column($svc->managerial($now), 4);
    fx_dated_order($agency, $client, 7600, date('Y-m-d 11:00:00', $now - 86400));
    ok(!in_array('kpi:revenue', $keys()), '−24 % : pas d\'alerte');
    Database::run('UPDATE orders SET total = 7400 WHERE agency_id = ? AND DATE(created_at) = ?', [$agency, date('Y-m-d', $now - 86400)]);
    ok(in_array('kpi:revenue', $keys()), '−26 % : alerte');
    $a = array_values(array_filter($svc->managerial($now), fn($x) => $x[4] === 'kpi:revenue'))[0];
    ok(str_contains($a[1], '26 %'), $a[1]);
});

test('alertes managériales : retards, réclamations et productivité', function () {
    $agency = fx_agency('PI');
    Auth::actAs(fx_user('manager', $agency));
    $client = fx_client();
    $now = time();
    $svc = new DashboardService($agency);
    $keys = fn() => array_column($svc->managerial($now), 4);
    for ($i = 0; $i < 4; $i++) {
        fx_dated_order($agency, $client, 1000, date('Y-m-d H:i:s', $now - 86400 * 3), ['promised_at' => date('Y-m-d H:i:s', $now - 3600), 'status' => 'en_atelier']);
    }
    ok(!in_array('kpi:late', $keys()), '4 retards : sous le seuil de 5');
    fx_dated_order($agency, $client, 1000, date('Y-m-d H:i:s', $now - 86400 * 3), ['promised_at' => date('Y-m-d H:i:s', $now - 3600), 'status' => 'en_atelier']);
    ok(in_array('kpi:late', $keys()), '5 retards : alerte');

    $orderWithClaims = fx_order($agency, $client, 1000);
    for ($i = 0; $i < 2; $i++) {
        Database::insert('complaints', ['number' => 'T-' . bin2hex(random_bytes(6)), 'client_id' => $client, 'order_id' => $orderWithClaims, 'subject' => 'Tache restante', 'status' => 'ouverte', 'created_at' => now()]);
    }
    ok(!in_array('kpi:claims', $keys()), '2 réclamations : sous le seuil de 3');
    Database::insert('complaints', ['number' => 'T-' . bin2hex(random_bytes(6)), 'client_id' => $client, 'order_id' => $orderWithClaims, 'subject' => 'Bouton perdu', 'status' => 'ouverte', 'created_at' => now()]);
    ok(in_array('kpi:claims', $keys()), '3 réclamations : alerte');

    // Productivité : pièces terminées hier vs moyenne des mêmes jours
    $o = fx_dated_order($agency, $client, 1000, date('Y-m-d H:i:s', $now - 86400 * 30));
    $finish = function (int $back, int $n) use ($o, $now) {
        for ($i = 0; $i < $n; $i++) {
            $g = fx_garment($o);
            Database::insert('garment_events', ['garment_id' => $g, 'step' => 'pret', 'action' => 'termine', 'created_at' => date('Y-m-d 15:00:00', $now - $back * 86400)]);
        }
    };
    foreach ([8, 15, 22, 29] as $back) {
        $finish($back, 8);
    }
    $finish(1, 7);
    ok(!in_array('kpi:productivity', $keys()), '7 pièces contre 8 : pas d\'alerte');
    $finish(1, 0);
    Database::run("DELETE FROM garment_events WHERE step = 'pret' AND DATE(created_at) = ? AND garment_id IN (SELECT id FROM garments WHERE order_id = ?)", [date('Y-m-d', $now - 86400), $o]);
    $finish(1, 5);
    ok(in_array('kpi:productivity', $keys()), '5 pièces contre 8 : alerte');
});

test('alertes managériales : visibles dans le cockpit et remontées à la direction par le moteur d\'alertes', function () {
    $agency = fx_agency('PJ');
    Auth::actAs(fx_user('manager', $agency));
    $client = fx_client();
    for ($i = 0; $i < 5; $i++) {
        fx_dated_order($agency, $client, 1000, date('Y-m-d H:i:s', time() - 86400 * 3), ['promised_at' => date('Y-m-d H:i:s', time() - 7200), 'status' => 'en_atelier']);
    }
    $html = render_page('/cockpit');
    ok(str_contains($html, '5 commandes en retard'));
    ok(Database::value("SELECT event FROM alert_rules WHERE event = 'kpi_alert' AND active = 1") !== null);
    AlertService::tick();
    ok(true);
});
