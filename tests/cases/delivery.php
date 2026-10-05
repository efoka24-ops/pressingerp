<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Domain\Role;
use App\Services\CashService;
use App\Services\DeliveryService;
use App\Services\OrderService;

/**
 * Commande prête, avec livraison à domicile et contrôle qualité conforme.
 * @return array{agency:int,order:int,delivery:int,driver:array,manager:array,client:int}
 */
function fx_delivery(int $total = 6000, int $paid = 0, bool $assigned = true): array
{
    $agency = fx_agency();
    $manager = fx_user('manager', $agency);
    $driver = fx_user('livreur', $agency);
    $counter = fx_user('comptoir', $agency);
    Auth::actAs($counter);
    $client = fx_client();
    $order = fx_order($agency, $client, $total);
    Database::update('orders', ['status' => 'pret', 'paid' => $paid, 'delivery_address' => 'Bastos, rue 1.234, derrière la pharmacie'], 'id = :id', ['id' => $order]);
    $g = fx_garment($order);
    Database::update('garments', ['step' => 'pret', 'status' => 'termine'], 'id = :id', ['id' => $g]);
    Database::insert('quality_checks', ['garment_id' => $g, 'user_id' => $manager['id'], 'result' => 'conforme', 'created_at' => now()]);
    $delivery = DeliveryService::ensureForOrder($order);
    if ($assigned) {
        (new DeliveryService())->assign($delivery, (int)$driver['id'], date('Y-m-d H:i', time() + 3600));
    }
    return ['agency' => $agency, 'order' => $order, 'delivery' => $delivery, 'driver' => $driver, 'manager' => $manager, 'client' => $client];
}

/** Code de remise envoyé au client pour cette commande (lu dans le message en file). */
function fx_otp(int $order): string
{
    $body = (string)Database::value("SELECT body FROM messages WHERE order_id = ? AND event = 'delivery_started' ORDER BY id DESC LIMIT 1", [$order]);
    preg_match('/\b(\d{4})\b/', $body, $m);
    return $m[1] ?? '';
}

function fx_depart(array $f): string
{
    Auth::actAs($f['driver']);
    (new DeliveryService())->start($f['delivery']);
    return fx_otp($f['order']);
}

test('livraison : une commande prête avec adresse crée sa livraison, une seule fois', function () {
    $f = fx_delivery(6000, 0, false);
    same('a_livrer', Database::value('SELECT status FROM deliveries WHERE id = ?', [$f['delivery']]));
    same($f['delivery'], DeliveryService::ensureForOrder($f['order']));
    same(1, (int)Database::value("SELECT COUNT(*) FROM deliveries WHERE order_id = ? AND kind = 'deliver'", [$f['order']]));
    same(6000, (int)Database::value('SELECT amount_due FROM deliveries WHERE id = ?', [$f['delivery']]));
});

test('livraison : départ refusé sans livreur affecté, accepté avec, et le client reçoit son code', function () {
    $f = fx_delivery(6000, 0, false);
    Auth::actAs(fx_user('comptoir', $f['agency']));
    throws(fn() => (new DeliveryService())->start($f['delivery']), 'Affectez d\'abord un livreur');
    $assigned = fx_delivery();
    $code = fx_depart($assigned);
    same('en_route', Database::value('SELECT status FROM deliveries WHERE id = ?', [$assigned['delivery']]));
    ok(preg_match('/^\d{4}$/', $code) === 1, 'code à 4 chiffres dans le message au client');
    ok(!str_contains((string)Database::value('SELECT otp_hash FROM deliveries WHERE id = ?', [$assigned['delivery']]), $code), 'le code n\'est stocké que haché');
});

test('livraison : l\'affectation exige un livreur actif et un créneau futur, et est réservée à la réception', function () {
    $f = fx_delivery(6000, 0, false);
    $svc = new DeliveryService();
    Auth::actAs(fx_user('comptoir', $f['agency']));
    throws(fn() => $svc->assign($f['delivery'], 0, date('Y-m-d H:i', time() + 3600)), 'livreur actif');
    throws(fn() => $svc->assign($f['delivery'], (int)$f['driver']['id'], date('Y-m-d H:i', time() - 86400)), 'futur');
    throws(fn() => $svc->assign($f['delivery'], (int)$f['driver']['id'], ''), 'Créneau');
    Auth::actAs($f['driver']);
    throws(fn() => $svc->assign($f['delivery'], (int)$f['driver']['id'], date('Y-m-d H:i', time() + 3600)), 'réservée');
});

test('livraison RG20 : aucune clôture sans preuve, un mauvais code est refusé', function () {
    $f = fx_delivery(6000, 6000);
    $code = fx_depart($f);
    $svc = new DeliveryService();
    throws(fn() => $svc->complete($f['delivery'], []), 'Preuve de livraison obligatoire');
    throws(fn() => $svc->complete($f['delivery'], ['code' => $code === '0000' ? '1111' : '0000']), 'Code de remise incorrect');
    same('en_route', Database::value('SELECT status FROM deliveries WHERE id = ?', [$f['delivery']]), 'rien n\'a changé');
    same('pret', Database::value('SELECT status FROM orders WHERE id = ?', [$f['order']]));
    $svc->complete($f['delivery'], ['code' => $code]);
    same('livre', Database::value('SELECT status FROM deliveries WHERE id = ?', [$f['delivery']]));
    same('livre', Database::value('SELECT status FROM orders WHERE id = ?', [$f['order']]));
    same('code', Database::value('SELECT kind FROM delivery_proofs WHERE delivery_id = ?', [$f['delivery']]));
    same('retire', Database::value('SELECT step FROM garments WHERE order_id = ?', [$f['order']]));
});

test('livraison RG20 : une signature invalide est refusée, une image qui n\'est pas un PNG aussi', function () {
    throws(fn() => DeliveryService::saveSignature('data:image/png;base64,AAAA'), 'invalide');
    throws(fn() => DeliveryService::saveSignature('data:text/html;base64,' . base64_encode(str_repeat('<script>', 60))), 'invalide');
    throws(fn() => DeliveryService::saveSignature('javascript:alert(1)'), 'invalide');
    throws(fn() => DeliveryService::saveSignature('data:image/png;base64,' . base64_encode(str_repeat('x', 400))), 'PNG');
});

test('livraison RG21 : le solde est encaissé par le livreur, dans sa propre caisse', function () {
    $f = fx_delivery(6000, 1000);
    $code = fx_depart($f);
    $svc = new DeliveryService();
    throws(fn() => $svc->complete($f['delivery'], ['code' => $code]), 'à encaisser, ou à reporter');
    throws(fn() => $svc->complete($f['delivery'], ['code' => $code], ['lines' => [['method' => 'orange', 'amount' => 5000]]]), 'Référence');
    throws(fn() => $svc->complete($f['delivery'], ['code' => $code], ['lines' => [['method' => 'carte', 'amount' => 5000]]]), 'espèces');
    same('en_route', Database::value('SELECT status FROM deliveries WHERE id = ?', [$f['delivery']]), 'tout ou rien');
    $svc->complete($f['delivery'], ['code' => $code], ['lines' => [['method' => 'especes', 'amount' => 3000], ['method' => 'orange', 'amount' => 2000, 'reference' => 'OM1234567']]]);
    same(6000, (int)Database::value('SELECT paid FROM orders WHERE id = ?', [$f['order']]));
    $session = (new CashService())->current((int)$f['driver']['id']);
    ok($session !== null, 'caisse du livreur ouverte automatiquement');
    same(0, (int)$session['opening_float']);
    same(2, (int)Database::value('SELECT COUNT(*) FROM payments WHERE order_id = ? AND cash_session_id = ?', [$f['order'], $session['id']]));
    $exp = (new CashService())->expected($session);
    same(3000, $exp['especes']);
    same(2000, $exp['orange']);
});

test('livraison RG21 : un responsable clôture la caisse du livreur, sans tolérance', function () {
    $f = fx_delivery(4000, 0);
    $code = fx_depart($f);
    (new DeliveryService())->complete($f['delivery'], ['code' => $code], ['lines' => [['method' => 'especes', 'amount' => 4000]]]);
    $session = (new CashService())->current((int)$f['driver']['id']);
    Auth::actAs($f['manager']);
    ok(Auth::can('cash', 'validate'));
    $counted = (new CashService())->expected($session);
    $counted['especes'] -= 500;
    throws(fn() => (new CashService())->close($session, $counted, ''), 'justification');
    same(-500, (new CashService())->close($session, $counted, 'Billet de 500 F manquant à la remise'));
    same(null, (new CashService())->current((int)$f['driver']['id']));
});

test('livraison RG21 : un report de solde exige un motif et l\'autorisation d\'un responsable', function () {
    $f = fx_delivery(5000, 0);
    $code = fx_depart($f);
    $svc = new DeliveryService();
    $auth = $f['manager'];
    throws(fn() => $svc->complete($f['delivery'], ['code' => $code], ['defer' => true, 'reason' => 'court', 'authoriser' => $auth]), 'Motif du report');
    throws(fn() => $svc->complete($f['delivery'], ['code' => $code], ['defer' => true, 'reason' => 'Client sans monnaie, paiera au comptoir']), 'autorisé par un responsable');
    $svc->complete($f['delivery'], ['code' => $code], ['defer' => true, 'reason' => 'Client sans monnaie, paiera au comptoir', 'authoriser' => $auth]);
    same('livre', Database::value('SELECT status FROM orders WHERE id = ?', [$f['order']]));
    same(0, (int)Database::value('SELECT paid FROM orders WHERE id = ?', [$f['order']]));
    same(1, (int)Database::value('SELECT deferred FROM deliveries WHERE id = ?', [$f['delivery']]));
    same(1, (int)Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'delivery.defer' AND entity_id = ?", [$f['order']]));
});

test('livraison : un client en compte n\'a rien à encaisser à la porte', function () {
    $f = fx_delivery(5000, 0);
    Database::update('orders', ['on_account' => 1], 'id = :id', ['id' => $f['order']]);
    $code = fx_depart($f);
    (new DeliveryService())->complete($f['delivery'], ['code' => $code]);
    same('livre', Database::value('SELECT status FROM deliveries WHERE id = ?', [$f['delivery']]));
    same(0, (int)Database::value('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$f['order']]));
});

test('livraison SE20 : un échec exige un motif et un nouveau créneau, prévient le client et se retente', function () {
    $f = fx_delivery(6000, 6000);
    fx_depart($f);
    $svc = new DeliveryService();
    throws(fn() => $svc->fail($f['delivery'], 'inconnu', '', date('Y-m-d H:i', time() + 7200)), 'motif');
    throws(fn() => $svc->fail($f['delivery'], 'absent', '', ''), 'Créneau');
    $svc->fail($f['delivery'], 'absent', 'Personne à la porte', date('Y-m-d H:i', time() + 7200));
    same('non_livre', Database::value('SELECT status FROM deliveries WHERE id = ?', [$f['delivery']]));
    same(1, (int)Database::value("SELECT COUNT(*) FROM messages WHERE order_id = ? AND event = 'delivery_failed'", [$f['order']]));
    same('pret', Database::value('SELECT status FROM orders WHERE id = ?', [$f['order']]), 'la commande reste prête');
    $code = fx_depart($f);
    same(2, (int)Database::value('SELECT attempts FROM deliveries WHERE id = ?', [$f['delivery']]));
    $svc->complete($f['delivery'], ['code' => $code]);
    same('livre', Database::value('SELECT status FROM orders WHERE id = ?', [$f['order']]));
});

test('livraison SE21 : adresse introuvable => alerte à la réception, correction tracée', function () {
    $f = fx_delivery(6000, 6000);
    fx_depart($f);
    (new DeliveryService())->fail($f['delivery'], 'adresse', '', date('Y-m-d H:i', time() + 7200));
    ok(Database::value("SELECT id FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL", ['dlvaddr:' . $f['delivery']]) !== null);
    Auth::actAs($f['driver']);
    throws(fn() => (new DeliveryService())->updateAddress($f['delivery'], 'Nouvelle adresse précise', 'Client joint'), 'réservée');
    Auth::actAs(fx_user('comptoir', $f['agency']));
    throws(fn() => (new DeliveryService())->updateAddress($f['delivery'], 'x', 'Client joint'), 'précise');
    (new DeliveryService())->updateAddress($f['delivery'], 'Omnisport, face stade, portail bleu', 'Client joint au téléphone');
    same('Omnisport, face stade, portail bleu', Database::value('SELECT delivery_address FROM orders WHERE id = ?', [$f['order']]));
    same(null, Database::value("SELECT id FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL", ['dlvaddr:' . $f['delivery']]));
});

test('livraison : trois échecs => alerte à la réception avec la suite à donner', function () {
    $f = fx_delivery(6000, 6000);
    $svc = new DeliveryService();
    for ($i = 0; $i < DeliveryService::MAX_ATTEMPTS; $i++) {
        fx_depart($f);
        $svc->fail($f['delivery'], 'absent', '', date('Y-m-d H:i', time() + 7200));
    }
    $a = Database::one("SELECT * FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL", ['dlvfail:' . $f['delivery']]);
    ok($a !== null && str_contains($a['message'], '3/3'));
});

test('livraison : un livreur ne touche que ses livraisons, et le retrait au comptoir est refusé pendant une livraison', function () {
    $f = fx_delivery(6000, 6000);
    $other = fx_user('livreur', $f['agency']);
    Auth::actAs($other);
    throws(fn() => (new DeliveryService())->start($f['delivery']), 'ne vous est pas affectée');
    Auth::actAs(fx_user('comptoir', $f['agency']));
    throws(fn() => (new OrderService())->pickup($f['order'], null), 'livraison en cours');
});

test('livraison : une commande non prête ne peut pas partir', function () {
    $f = fx_delivery(6000, 6000);
    Database::update('orders', ['status' => 'en_atelier'], 'id = :id', ['id' => $f['order']]);
    Auth::actAs($f['driver']);
    throws(fn() => (new DeliveryService())->start($f['delivery']), 'pas prête');
});

test('collecte : demande avec client identifiable, adresse et créneau, puis collectée et rattachée à la commande', function () {
    $agency = fx_agency();
    $counter = fx_user('comptoir', $agency);
    $driver = fx_user('livreur', $agency);
    Auth::actAs($counter);
    $client = fx_client();
    $svc = new DeliveryService();
    throws(fn() => $svc->createCollect($client, 'rue', date('Y-m-d H:i', time() + 3600)), 'Adresse précise');
    throws(fn() => $svc->createCollect($client, 'Bastos, rue 1.234, villa 12', ''), 'Créneau');
    $id = $svc->createCollect($client, 'Bastos, rue 1.234, villa 12', date('Y-m-d H:i', time() + 3600));
    $svc->assign($id, (int)$driver['id'], date('Y-m-d H:i', time() + 3600));
    throws(fn() => DeliveryService::linkCollectToOrder($id, fx_order($agency, $client)), 'pas encore marquée');
    Auth::actAs($driver);
    $svc->markCollected($id);
    same('collecte', Database::value('SELECT status FROM deliveries WHERE id = ?', [$id]));
    Auth::actAs($counter);
    $order = fx_order($agency, $client);
    DeliveryService::linkCollectToOrder($id, $order);
    same($order, (int)Database::value('SELECT order_id FROM deliveries WHERE id = ?', [$id]));
    same('en_traitement', Database::value('SELECT status FROM deliveries WHERE id = ?', [$id]));
});

test('livraison : la commande qui devient prête met sa livraison « à livrer »', function () {
    $agency = fx_agency();
    Auth::actAs(fx_user('comptoir', $agency));
    $order = fx_order($agency, fx_client(), 3000);
    Database::update('orders', ['delivery_address' => 'Mvog-Mbi, carrefour, boutique Chez Paul'], 'id = :id', ['id' => $order]);
    $id = DeliveryService::ensureForOrder($order);
    same('en_traitement', Database::value('SELECT status FROM deliveries WHERE id = ?', [$id]));
    $g = fx_garment($order);
    Database::update('garments', ['step' => 'pret', 'status' => 'termine'], 'id = :id', ['id' => $g]);
    (new OrderService())->refreshStatus($order);
    same('a_livrer', Database::value('SELECT status FROM deliveries WHERE id = ?', [$id]));
});

test('livraison : droits — le livreur lit et met à jour, ne crée pas ; la réception crée', function () {
    ok(Role::Livreur->can('delivery', 'read') && Role::Livreur->can('delivery', 'update'));
    ok(!Role::Livreur->can('delivery', 'create') && !Role::Livreur->can('cash', 'read') && !Role::Livreur->can('clients', 'read'));
    ok(Role::Comptoir->can('delivery', 'create') && Role::Comptoir->can('delivery', 'update'));
    ok(!Role::Atelier->can('delivery', 'read'));
    same('/livraisons', Role::Livreur->home());
});

test('livraison : un livreur ne voit pas la livraison d\'un autre, ni celle d\'une autre agence', function () {
    $f = fx_delivery();
    Auth::actAs(fx_user('livreur', $f['agency']));
    throws(fn() => render_page('/livraisons/' . $f['delivery']), 'introuvable');
    Auth::actAs(fx_user('comptoir', fx_agency()));
    throws(fn() => render_page('/livraisons/' . $f['delivery']), 'introuvable');
});

test('pages : tournée du livreur, liste de la réception, fiche de livraison et demande de collecte se rendent', function () {
    $f = fx_delivery();
    fx_depart($f);
    $html = render_page('/livraisons');
    ok(str_contains($html, 'Ma tournée') && str_contains($html, 'Bastos'));
    $html = render_page('/livraisons/' . $f['delivery']);
    ok(str_contains($html, 'Remise au client') && str_contains($html, 'id="sig"') && str_contains($html, 'Livraison impossible'));
    Auth::actAs(fx_user('comptoir', $f['agency']));
    ok(str_contains(render_page('/livraisons?onglet=en_cours'), 'Bastos'));
    ok(str_contains(render_page('/livraisons/' . $f['delivery']), 'Corriger l\'adresse'));
    ok(str_contains(render_page('/livraisons/collecte'), 'Demande de collecte'));
    ok(str_contains(render_page('/commandes/' . $f['order']), 'Livraison à domicile'));
});
