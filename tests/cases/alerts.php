<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Domain\IncidentType;
use App\Domain\Step;
use App\Services\AlertService;
use App\Services\QualityService;
use App\Services\WorkflowService;

function alert_by_key(string $key): ?array
{
    return Database::one('SELECT * FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL', [$key]);
}

/** Commande de l'agence, avec une pièce, promise à $promisedTs. @return array{0:int,1:int,2:int} [agence, commande, pièce] */
function fx_alert_order(int $promisedTs, string $orderStatus = 'en_atelier'): array
{
    $agency = fx_agency();
    $order = fx_order($agency, fx_client());
    Database::update('orders', ['promised_at' => date('Y-m-d H:i:s', $promisedTs), 'status' => $orderStatus], 'id = :id', ['id' => $order]);
    $g = fx_garment($order);
    Database::update('garments', ['treatment_id' => fx_treatment('complet')], 'id = :id', ['id' => $g]);
    return [$agency, $order, $g];
}

test('alertes : commande en retard (rouge) pour le responsable de son agence, fermée quand elle est prête', function () {
    $now = time();
    [$agency, $order] = fx_alert_order($now - 7200);
    AlertService::tick($now);
    $a = alert_by_key('late:' . $order);
    ok($a !== null && $a['level'] === 'critical' && $a['target_role'] === 'manager' && (int)$a['agency_id'] === $agency && str_contains($a['message'], 'en retard'), 'alerte de retard');
    ok(in_array($a['id'], array_column(AlertService::visible(fx_user('manager', $agency)), 'id')), 'le responsable la voit');
    ok(!in_array($a['id'], array_column(AlertService::visible(fx_user('manager', fx_agency())), 'id')), 'pas une autre agence');
    AlertService::tick($now);
    same(1, (int)Database::value('SELECT COUNT(*) FROM alerts WHERE dedupe_key = ?', ['late:' . $order]), 'jamais de doublon');
    Database::update('orders', ['status' => 'pret'], 'id = :id', ['id' => $order]);
    AlertService::tick($now);
    ok(alert_by_key('late:' . $order) === null, 'fermée quand la commande est prête');
});

test('alertes : commande à risque (orange) puis en retard (rouge) selon la date promise', function () {
    $now = time();
    [, $order] = fx_alert_order($now + 3600);
    AlertService::tick($now);
    ok(alert_by_key('risk:' . $order) !== null && alert_by_key('late:' . $order) === null, 'orange à moins de 3 h');
    [, $fine] = fx_alert_order($now + 86400);
    AlertService::tick($now);
    ok(alert_by_key('risk:' . $fine) === null && alert_by_key('late:' . $fine) === null, 'vert : aucune alerte');
    AlertService::tick($now + 7200);
    ok(alert_by_key('risk:' . $order) !== null || alert_by_key('late:' . $order) !== null, 'toujours suivie');
    AlertService::tick($now + 3 * 3600);
    ok(alert_by_key('late:' . $order) !== null && alert_by_key('risk:' . $order) === null, 'passe au rouge une fois la date dépassée');
});

test('alertes : pièce non prise en charge après le délai, escalade au responsable, fermée à la prise en charge', function () {
    $now = time();
    [, , $g] = fx_alert_order($now + 5 * 86400);
    Database::update('garments', ['step' => 'lavage', 'status' => 'a_traiter', 'step_since' => date('Y-m-d H:i:s', $now - 3600)], 'id = :id', ['id' => $g]);
    AlertService::tick($now);
    ok(alert_by_key('stale:' . $g) === null, 'pas avant le délai paramétré (120 min)');
    AlertService::tick($now + 3600 + 60);
    $a = alert_by_key('stale:' . $g);
    ok($a !== null && $a['target_role'] === 'superviseur' && $a['escalated_at'] === null && str_contains($a['message'], 'non prise en charge'), 'alerte au superviseur');
    AlertService::tick($now + 3600 + 60 + 119 * 60);
    ok(alert_by_key('stale:' . $g)['escalated_at'] === null, 'pas encore escaladée');
    AlertService::tick($now + 3600 + 60 + 125 * 60);
    $a = alert_by_key('stale:' . $g);
    ok($a['escalated_role'] === 'manager' && $a['level'] === 'critical' && $a['escalated_at'] !== null, 'escalade vers le responsable');
    $agency = fx_agency();
    ok(in_array($a['id'], array_column(AlertService::visible(fx_user('manager', $agency)), 'id')), 'le responsable la voit');
    Auth::actAs(fx_user('atelier', $agency));
    (new WorkflowService())->start($g);
    ok(alert_by_key('stale:' . $g) === null, 'fermée dès la prise en charge');
});

test('alertes : une alerte prise en compte avant le délai ne monte pas', function () {
    $now = time();
    [, , $g] = fx_alert_order($now + 5 * 86400);
    Database::update('garments', ['step' => 'repassage', 'status' => 'a_traiter', 'step_since' => date('Y-m-d H:i:s', $now - 7200)], 'id = :id', ['id' => $g]);
    AlertService::tick($now);
    $a = alert_by_key('stale:' . $g);
    ok($a !== null);
    Auth::actAs(fx_user('superviseur', fx_agency()));
    AlertService::acknowledge((int)$a['id']);
    AlertService::tick($now + 4 * 3600);
    $a = alert_by_key('stale:' . $g);
    ok($a !== null && $a['ack_at'] !== null && $a['escalated_at'] === null, 'prise en compte : pas d\'escalade');
});

test('alertes : un incident bloque la pièce et alerte aussitôt le superviseur ; critique, le responsable', function () {
    [$agency, , $g] = fx_alert_order(time() + 5 * 86400);
    Auth::actAs(fx_user('atelier', $agency));
    $wf = new WorkflowService();
    $wf->block($g, IncidentType::Machine, 'Machine à laver en panne');
    $a = alert_by_key('blocked:' . $g);
    ok($a !== null && $a['target_role'] === 'superviseur' && str_contains($a['message'], 'Machine à laver en panne'), 'alerte immédiate au superviseur');
    $sup = fx_user('superviseur', $agency);
    ok(in_array($a['id'], array_column(AlertService::visible($sup), 'id')), 'visible du superviseur');
    ok(!in_array($a['id'], array_column(AlertService::visible(fx_user('atelier', $agency)), 'id')), 'invisible de l\'opérateur');
    $wf->unblock($g, 'Machine réparée');
    ok(alert_by_key('blocked:' . $g) === null, 'fermée à la levée');

    $g2 = fx_garment(fx_order($agency, fx_client()));
    $inc = $wf->block($g2, IncidentType::Endommage, 'Brûlure au repassage');
    $c = alert_by_key('incident:' . $inc);
    ok($c !== null && $c['target_role'] === 'manager' && $c['level'] === 'critical', 'incident critique : alerte au responsable');
    ok(in_array($c['id'], array_column(AlertService::visible(fx_user('direction', fx_agency())), 'id')), 'visible de la direction');
});

test('alertes : fin de traitement, nombre de pièces disponibles au poste suivant', function () {
    [$agency, , $g1] = fx_alert_order(time() + 5 * 86400);
    $order2 = fx_order($agency, fx_client());
    Database::update('orders', ['status' => 'en_atelier'], 'id = :id', ['id' => $order2]);
    $g2 = fx_garment($order2);
    foreach ([$g1, $g2] as $g) {
        Database::update('garments', ['step' => 'lavage', 'status' => 'en_cours', 'treatment_id' => fx_treatment('complet')], 'id = :id', ['id' => $g]);
    }
    Auth::actAs(fx_user('atelier', $agency));
    $before = (int)Database::value("SELECT COUNT(*) FROM garments g JOIN orders o ON o.id = g.order_id WHERE g.step = 'sechage' AND g.status IN ('a_traiter','a_reprendre') AND o.status = 'en_atelier'");
    $wf = new WorkflowService();
    $wf->complete($g1);
    $a = alert_by_key('transfer:sechage');
    ok($a !== null && $a['target_role'] === 'atelier' && str_contains($a['message'], ($before + 1) . ' pièce'), 'alerte au poste suivant : ' . ($a['message'] ?? 'aucune'));
    $wf->complete($g2);
    ok(str_contains(alert_by_key('transfer:sechage')['message'], ($before + 2) . ' pièces'), 'le compteur monte');
    $wf->start($g1);
    ok(str_contains((string)(alert_by_key('transfer:sechage')['message'] ?? ''), ($before + 1) . ' pièce'), 'le compteur redescend à la prise en charge');
});

test('alertes : reprise qualité vers le service concerné avec le motif, puis escalade', function () {
    $now = time();
    [$agency, , $g] = fx_alert_order($now + 5 * 86400);
    Database::update('garments', ['step' => 'controle', 'status' => 'a_traiter'], 'id = :id', ['id' => $g]);
    Auth::actAs(fx_user('qualite', $agency));
    (new QualityService())->check($g, ['repassage'], 'Repassage imparfait', Step::Repassage);
    $a = alert_by_key('rework:' . $g);
    ok($a !== null && $a['target_role'] === 'atelier' && str_contains($a['message'], 'Repassage imparfait') && str_contains($a['message'], 'Repassage'), 'alerte avec le motif');
    AlertService::tick(time() + 3700);
    ok(alert_by_key('rework:' . $g)['escalated_role'] === 'superviseur', 'escalade au superviseur après 60 min');
    Auth::actAs(fx_user('atelier', $agency));
    (new WorkflowService())->start($g);
    ok(alert_by_key('rework:' . $g) === null, 'fermée à la reprise du travail');
});

test('alertes : règles paramétrables (désactivation, destinataire) et visibilité par profil', function () {
    $now = time();
    [$agency, $order] = fx_alert_order($now - 3600);
    Database::update('alert_rules', ['active' => 0], 'event = :e', ['e' => 'order_late']);
    AlertService::resetCache();
    AlertService::tick($now);
    ok(alert_by_key('late:' . $order) === null, 'règle désactivée : aucune alerte');
    Database::update('alert_rules', ['active' => 1, 'target_role' => 'direction'], 'event = :e', ['e' => 'order_late']);
    AlertService::resetCache();
    AlertService::tick($now);
    $a = alert_by_key('late:' . $order);
    ok($a !== null && $a['target_role'] === 'direction', 'destinataire modifié');
    ok(in_array($a['id'], array_column(AlertService::visible(fx_user('direction', fx_agency())), 'id')));
    ok(!in_array($a['id'], array_column(AlertService::visible(fx_user('comptoir', $agency)), 'id')), 'le comptoir ne voit pas les alertes de la direction');
    Database::update('alert_rules', ['target_role' => 'manager'], 'event = :e', ['e' => 'order_late']);
    AlertService::resetCache();
});

test('alertes : stock critique', function () {
    $item = Database::insert('stock_items', ['name' => 'Détachant test', 'unit' => 'L', 'quantity' => 1, 'min_qty' => 10]);
    AlertService::tick(time());
    ok(alert_by_key('stock:' . $item) !== null, 'stock sous le minimum');
    Database::update('stock_items', ['quantity' => 50], 'id = :id', ['id' => $item]);
    AlertService::tick(time());
    ok(alert_by_key('stock:' . $item) === null, 'fermée une fois réapprovisionné');
});

test('alertes : la prise en compte est réservée aux destinataires', function () {
    $now = time();
    [$agency, $order] = fx_alert_order($now - 3600);
    AlertService::tick($now);
    $a = alert_by_key('late:' . $order);
    Auth::actAs(fx_user('comptoir', $agency));
    throws(fn() => AlertService::acknowledge((int)$a['id']), 'ne vous est pas destinée');
    Auth::actAs(fx_user('manager', $agency));
    AlertService::acknowledge((int)$a['id']);
    ok(Database::value('SELECT ack_by FROM alerts WHERE id = ?', [$a['id']]) !== null);
    ok(!in_array($a['id'], array_column(array_filter(AlertService::visible(fx_user('manager', $agency)), fn($x) => !$x['ack_at']), 'id')), 'plus comptée comme nouvelle');
});
