<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Services\OfflineService;
use App\Services\PricingService;

/** Poste autorisé + plage de numéros, dans une agence neuve. @return array{0:array,1:array,2:int,3:array} [poste, utilisateur, agence, plage] */
function fx_station(string $label = 'Poste test'): array
{
    $agency = fx_agency();
    $manager = fx_user('manager', $agency);
    Auth::actAs($manager);
    $svc = new OfflineService();
    $reg = $svc->register($agency, $label);
    $ws = $svc->authenticate($reg['token']);
    $block = $svc->allocateBlockIfNeeded($ws);
    return [$ws, $manager, $agency, $block];
}

function off_payload(array $block, int $offset, array $extra = []): array
{
    $now = date('Y-m-d H:i:s');
    return array_merge([
        'number' => sprintf('PR-%d-%06d', $block['year'], $block['from'] + $offset),
        'created_at' => $now, 'snapshot_at' => $now, 'service_level' => 'standard',
        'client' => ['name' => 'Jean Mbarga', 'phone' => '6' . random_int(10000000, 99999999), 'type' => 'particulier'],
        'lines' => [['article_id' => 0, 'qty' => 2, 'treatment_id' => 1]],
        'delivery' => false, 'notes' => '', 'total' => 2000, 'tracking_token' => bin2hex(random_bytes(16)),
    ], $extra);
}

test('hors-ligne : un poste est autorisé par jeton, le jeton n\'est jamais stocké en clair', function () {
    [$ws, $manager, $agency] = fx_station();
    $token = Database::value('SELECT token_hash FROM workstations WHERE id = ?', [$ws['id']]);
    same(64, strlen((string)$token));
    $svc = new OfflineService();
    throws(fn() => $svc->authenticate(''), 'non autorisé');
    throws(fn() => $svc->authenticate('mauvais-jeton'), 'non autorisé');
    throws(fn() => $svc->register($agency, '  '), 'nom au poste');
    throws(fn() => $svc->register(0, 'x'), 'Agence invalide');
});

test('hors-ligne : un poste désactivé ou d\'une autre agence est refusé', function () {
    [$ws, $manager, $agency] = fx_station();
    $svc = new OfflineService();
    $reg = $svc->register($agency, 'Second poste');
    Auth::actAs(fx_user('comptoir', fx_agency()));
    throws(fn() => $svc->authenticate($reg['token']), 'autre agence');
    Auth::actAs($manager);
    $svc->authenticate($reg['token']);
    throws(fn() => $svc->deactivate($reg['id'], ''), 'Motif');
    $svc->deactivate($reg['id'], 'Tablette perdue');
    throws(fn() => $svc->authenticate($reg['token']), 'non autorisé');
});

test('numéros : plages réservées sans chevauchement entre postes, avancement du compteur commun', function () {
    [$ws1, , , $b1] = fx_station('Poste 1');
    [$ws2, , , $b2] = fx_station('Poste 2');
    ok($b1 !== null && $b2 !== null, 'plages délivrées');
    same($b1['year'], $b2['year']);
    ok($b2['from'] > $b1['to'], 'les plages ne se chevauchent pas');
    ok($b1['to'] - $b1['from'] + 1 >= 10, 'plage d\'au moins 10 numéros');
    $next = \App\Services\Numbering::next('order', 'PR-%d-%06d');
    ok($next === sprintf('PR-%d-%06d', $b2['year'], $b2['to'] + 1), 'la saisie en ligne continue après les plages réservées : ' . $next);
});

test('numéros : pas de nouvelle plage tant qu\'il reste assez de numéros', function () {
    [$ws, , , $b] = fx_station();
    $svc = new OfflineService();
    same(null, $svc->allocateBlockIfNeeded($ws), 'plage suffisante');
    same($b['to'] - $b['from'] + 1, $svc->unused($ws));
    // on consomme la plage jusqu'au seuil bas : une nouvelle plage est alors délivrée
    $size = $b['to'] - $b['from'] + 1;
    for ($i = 0; $i < $size - 5; $i++) {
        Database::insert('offline_syncs', ['workstation_id' => $ws['id'], 'number' => sprintf('PR-%d-%06d', $b['year'], $b['from'] + $i), 'status' => 'ok', 'received_at' => now()]);
    }
    $second = $svc->allocateBlockIfNeeded($ws);
    ok($second !== null && $second['from'] > $b['to'], 'nouvelle plage à court de numéros');
});

test('synchronisation : crée la commande avec le numéro réservé, de façon idempotente', function () {
    [$ws, $manager, $agency, $block] = fx_station();
    $a = fx_article('Chemise', 1000);
    $p = off_payload($block, 0, ['lines' => [['article_id' => $a, 'qty' => 2, 'treatment_id' => fx_treatment('repassage')]]]);
    $svc = new OfflineService();
    $r = $svc->syncOrder($ws, $p);
    same('created', $r['status'], (string)$r['message']);
    $o = Database::one('SELECT * FROM orders WHERE id = ?', [$r['order_id']]);
    same($p['number'], $o['number']);
    same($p['tracking_token'], $o['tracking_token'], 'jeton de suivi du ticket imprimé');
    same((int)$ws['id'], (int)$o['workstation_id']);
    same((int)$agency, (int)$o['agency_id']);
    ok($o['offline_created_at'] !== null && $o['synced_at'] !== null);
    same(2000, (int)$o['total']);
    $codes = Database::all('SELECT code, treatment_id FROM garments WHERE order_id = ? ORDER BY seq', [$o['id']]);
    same([$p['number'] . '-01', $p['number'] . '-02'], array_column($codes, 'code'));
    same(fx_treatment('repassage'), (int)$codes[0]['treatment_id']);

    $again = $svc->syncOrder($ws, $p);
    same('duplicate', $again['status']);
    same((int)$r['order_id'], (int)$again['order_id']);
    same(1, (int)Database::value('SELECT COUNT(*) FROM orders WHERE number = ?', [$p['number']]), 'aucun doublon');
});

test('synchronisation : un numéro hors plage ou d\'un autre poste est refusé', function () {
    [$ws1, , , $b1] = fx_station('A');
    [$ws2, , , $b2] = fx_station('B');
    $a = fx_article('Pantalon', 1200);
    $svc = new OfflineService();
    Auth::actAs(fx_user('manager', (int)$ws1['agency_id']));
    $mine = off_payload($b1, 0, ['lines' => [['article_id' => $a, 'qty' => 1]]]);
    $theirs = off_payload($b2, 0, ['lines' => [['article_id' => $a, 'qty' => 1]]]);
    $r = $svc->syncOrder($ws1, $theirs);
    same('rejected', $r['status']);
    ok(str_contains((string)$r['message'], 'plages'), (string)$r['message']);
    same('rejected', $svc->syncOrder($ws1, ['number' => 'PR-2026-9', 'lines' => []])['status'], 'numéro mal formé');
    same(0, (int)Database::value('SELECT COUNT(*) FROM orders WHERE number = ?', [$theirs['number']]), 'rien créé');
    same('created', $svc->syncOrder($ws1, $mine)['status']);
});

test('synchronisation : client non identifiable, photo manquante ou client en compte => rejetée et tracée', function () {
    [$ws, $manager, $agency, $block] = fx_station();
    $svc = new OfflineService();
    $a = fx_article('Chemise', 1000);
    $robe = fx_article('Robe', 6000, true);

    $anon = off_payload($block, 0, ['client' => ['name' => 'Anonyme', 'phone' => '670123456'], 'lines' => [['article_id' => $a, 'qty' => 1]]]);
    $r = $svc->syncOrder($ws, $anon);
    same('rejected', $r['status']);
    ok(str_contains((string)$r['message'], 'non identifiable'), (string)$r['message']);

    $nophoto = off_payload($block, 1, ['lines' => [['article_id' => $robe, 'qty' => 1]]]);
    $r = $svc->syncOrder($ws, $nophoto);
    same('rejected', $r['status']);
    ok(str_contains((string)$r['message'], 'Photo obligatoire'), (string)$r['message']);

    $pro = Database::insert('clients', ['code' => 'T' . bin2hex(random_bytes(5)), 'name' => 'Hôtel Test', 'phone' => '+2376' . random_int(10000000, 99999999), 'type' => 'pro', 'credit_limit' => 1000000]);
    Database::insert('contracts', ['client_id' => $pro, 'start_date' => date('Y-m-d', time() - 86400), 'end_date' => date('Y-m-d', time() + 86400 * 30), 'discount_pct' => 0, 'active' => 1]);
    $acc = off_payload($block, 2, ['client' => ['id' => $pro], 'lines' => [['article_id' => $a, 'qty' => 1]]]);
    $r = $svc->syncOrder($ws, $acc);
    same('rejected', $r['status']);
    ok(str_contains((string)$r['message'], 'en compte'), (string)$r['message']);

    same(3, (int)Database::value("SELECT COUNT(*) FROM offline_syncs WHERE workstation_id = ? AND status = 'rejected'", [$ws['id']]));
    $log = Database::one("SELECT * FROM audit_log WHERE action = 'offline.rejected' ORDER BY id DESC LIMIT 1");
    ok($log !== null, 'rejet non journalisé');
    $stat = array_values(array_filter(OfflineService::stations(), fn($s) => (int)$s['id'] === (int)$ws['id']))[0];
    same(3, (int)$stat['rejected']);
    same((int)$stat['issued'] - 3, (int)$stat['unused']);
});

test('synchronisation : un rejet peut être retenté une fois corrigé', function () {
    [$ws, , , $block] = fx_station();
    $svc = new OfflineService();
    $a = fx_article('Chemise', 1000);
    $bad = off_payload($block, 0, ['client' => ['name' => 'Anonyme', 'phone' => '670123456'], 'lines' => [['article_id' => $a, 'qty' => 1]]]);
    same('rejected', $svc->syncOrder($ws, $bad)['status']);
    $fixed = $bad;
    $fixed['client'] = ['name' => 'Anne Mballa', 'phone' => '670123456', 'type' => 'particulier'];
    $r = $svc->syncOrder($ws, $fixed);
    same('created', $r['status'], (string)$r['message']);
});

test('synchronisation : un client déjà connu est retrouvé par son numéro, pas dupliqué', function () {
    [$ws, , , $block] = fx_station();
    $a = fx_article('Chemise', 1000);
    $phone = '+2376' . random_int(10000000, 99999999);
    $existing = Database::insert('clients', ['code' => 'T' . bin2hex(random_bytes(5)), 'name' => 'Paul Essomba', 'phone' => $phone]);
    $p = off_payload($block, 0, ['client' => ['name' => 'Paul Essomba', 'phone' => substr($phone, 4), 'type' => 'particulier'], 'lines' => [['article_id' => $a, 'qty' => 1]]]);
    $r = (new OfflineService())->syncOrder($ws, $p);
    same('created', $r['status'], (string)$r['message']);
    same($existing, (int)Database::value('SELECT client_id FROM orders WHERE id = ?', [$r['order_id']]));
    same(1, (int)Database::value('SELECT COUNT(*) FROM clients WHERE phone = ?', [$phone]), 'pas de doublon de fiche');
});

test('prix : le serveur applique les tarifs en vigueur au moment du cache du poste et signale l\'écart avec le ticket', function () {
    [$ws, , , $block] = fx_station();
    $a = fx_article('Veste', 0);
    $std = (int)Database::value("SELECT id FROM price_lists WHERE kind = 'standard' LIMIT 1");
    $yesterday = date('Y-m-d H:i:s', time() - 86400);
    Database::insert('price_items', ['list_id' => $std, 'article_id' => $a, 'price' => 1000, 'reason' => 'test', 'created_at' => date('Y-m-d H:i:s', time() - 5 * 86400)]);
    Database::insert('price_items', ['list_id' => $std, 'article_id' => $a, 'price' => 2000, 'reason' => 'hausse', 'created_at' => date('Y-m-d H:i:s', time() - 3600)]);
    PricingService::resetCache();

    // Cache du poste pris hier : le ticket affichait 1000, le tarif a changé depuis. Le serveur reconstitue 1000.
    $p = off_payload($block, 0, ['snapshot_at' => $yesterday, 'created_at' => date('Y-m-d H:i:s', time() - 60), 'lines' => [['article_id' => $a, 'qty' => 1]], 'total' => 1000]);
    $r = (new OfflineService())->syncOrder($ws, $p);
    same('created', $r['status'], (string)$r['message']);
    same(1000, (int)Database::value('SELECT total FROM orders WHERE id = ?', [$r['order_id']]), 'tarif en vigueur à l\'heure du cache');
    same(0, (int)Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'offline.total_mismatch' AND entity_id = ?", [$r['order_id']]), 'pas d\'écart');

    // Ticket falsifié ou périmé : écart signalé, le prix du serveur est conservé
    $p2 = off_payload($block, 1, ['snapshot_at' => $yesterday, 'created_at' => date('Y-m-d H:i:s', time() - 60), 'lines' => [['article_id' => $a, 'qty' => 1]], 'total' => 10]);
    $r2 = (new OfflineService())->syncOrder($ws, $p2);
    same(1000, (int)Database::value('SELECT total FROM orders WHERE id = ?', [$r2['order_id']]));
    $log = Database::one("SELECT * FROM audit_log WHERE action = 'offline.total_mismatch' AND entity_id = ?", [$r2['order_id']]);
    ok($log !== null && $log['old_value'] === '10' && $log['new_value'] === '1000', 'écart tracé');
    ok(str_contains((string)Database::value('SELECT notes FROM orders WHERE id = ?', [$r2['order_id']]), 'Écart de prix'), 'note sur la commande');
});

test('horodatage : une heure de saisie dans le futur est ramenée à maintenant', function () {
    [$ws, , , $block] = fx_station();
    $a = fx_article('Chemise', 1000);
    $p = off_payload($block, 0, ['created_at' => date('Y-m-d H:i:s', time() + 86400 * 10), 'lines' => [['article_id' => $a, 'qty' => 1]], 'total' => 1000]);
    $r = (new OfflineService())->syncOrder($ws, $p);
    $created = strtotime((string)Database::value('SELECT created_at FROM orders WHERE id = ?', [$r['order_id']]));
    ok($created <= time() + 5, 'pas de commande datée du futur');
});

test('snapshot : tarifs résolus, clients récents identifiables, clients en compte bloqués', function () {
    [$ws, $manager, $agency] = fx_station();
    $a = fx_article('Chemise', 1000);
    $vipPrice = fx_list('vip');
    fx_price($vipPrice, $a, 900);
    $client = fx_client();
    Database::insert('orders', ['number' => 'T-' . bin2hex(random_bytes(6)), 'tracking_token' => bin2hex(random_bytes(16)), 'client_id' => $client, 'agency_id' => $agency, 'promised_at' => now(), 'created_at' => now()]);
    $incomplete = Database::insert('clients', ['code' => 'T' . bin2hex(random_bytes(5)), 'name' => 'Anonyme', 'phone' => '+2376' . random_int(10000000, 99999999)]);
    Database::insert('orders', ['number' => 'T-' . bin2hex(random_bytes(6)), 'tracking_token' => bin2hex(random_bytes(16)), 'client_id' => $incomplete, 'agency_id' => $agency, 'promised_at' => now(), 'created_at' => now()]);

    $snap = (new OfflineService())->snapshot($ws);
    same(1000, $snap['prices']['normal'][$a]);
    same(900, $snap['prices']['vip'][$a]);
    $byId = array_column($snap['clients'], null, 'id');
    ok(isset($byId[$client]) && $byId[$client]['blocked'] === null, 'client récent sélectionnable');
    ok(isset($byId[$incomplete]) && str_contains((string)$byId[$incomplete]['blocked'], 'incomplète'), 'fiche incomplète bloquée');
    same($agency, $snap['agency']['id']);
    ok(count($snap['levels']) === 3 && count($snap['treatments']) >= 1);
    $json = json_encode($snap);
    ok(!str_contains((string)$json, 'password') && !str_contains((string)$json, 'token'), 'aucun secret dans les données du poste');
});
