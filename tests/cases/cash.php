<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Domain\PaymentMethod;
use App\Services\AlertService;
use App\Services\Authorizer;
use App\Services\CashService;
use App\Services\MobileMoneyService;
use App\Services\OrderService;
use App\Services\PaymentService;

/** Caisse ouverte par un agent de comptoir et commande de l'agence. @return array{0:array,1:array,2:int,3:int} [agent, session, agence, commande] */
function fx_cash(int $total = 5000): array
{
    $agency = fx_agency();
    $agent = fx_user('comptoir', $agency);
    Auth::actAs($agent);
    $cash = new CashService();
    $cash->open((int)$agent['id'], $agency, 10000, 'Caisse test');
    $order = fx_order($agency, fx_client(), $total);
    return [$agent, $cash->current((int)$agent['id']), $agency, $order];
}

test('caisse : zéro tolérance, un écart d\'un seul franc exige une justification détaillée', function () {
    [$agent, $session] = fx_cash();
    $cash = new CashService();
    $exp = $cash->expected($session);
    $counted = $exp;
    $counted['especes'] += 1;
    throws(fn() => $cash->close($session, $counted, ''), 'justification détaillée');
    throws(fn() => $cash->close($session, $counted, 'oups'), 'justification détaillée');
    same(1, $cash->close($session, $counted, 'Pièce de 1 F tombée dans la caisse'));
    same(1, (int)Database::value('SELECT variance FROM cash_sessions WHERE id = ?', [$session['id']]));
});

test('caisse : un écart compensé entre deux modes est quand même un écart', function () {
    [$agent, $session] = fx_cash();
    $cash = new CashService();
    $counted = $cash->expected($session);
    $counted['especes'] += 1000;
    $counted['orange'] -= 1000;
    throws(fn() => $cash->close($session, $counted, ''), 'justification');
    same(0, $cash->close($session, $counted, 'Erreur de saisie du mode, vérifiée avec le client'), 'total nul mais écarts par mode');
    ok(Database::value("SELECT COUNT(*) FROM alerts WHERE dedupe_key = ?", ['cash:' . $session['id']]) > 0, 'alerte malgré un total nul');
});

test('caisse : sans écart, la clôture passe sans justification ni alerte', function () {
    [$agent, $session] = fx_cash();
    $cash = new CashService();
    same(0, $cash->close($session, $cash->expected($session), ''));
    same(0, (int)Database::value('SELECT COUNT(*) FROM alerts WHERE dedupe_key = ?', ['cash:' . $session['id']]));
});

test('caisse : un écart alerte le responsable de l\'agence, qui prend acte', function () {
    [$agent, $session, $agency] = fx_cash();
    $cash = new CashService();
    $counted = $cash->expected($session);
    $counted['especes'] -= 2500;
    $cash->close($session, $counted, 'Monnaie rendue en trop au client');
    $alert = Database::one('SELECT * FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL', ['cash:' . $session['id']]);
    ok($alert !== null && $alert['level'] === 'critical' && str_contains($alert['message'], 'Espèces') && str_contains($alert['message'], 'Monnaie rendue'), 'alerte critique détaillée');
    $manager = fx_user('manager', $agency);
    $other = fx_user('manager', fx_agency());
    $direction = fx_user('direction', fx_agency());
    ok(in_array($alert['id'], array_column(AlertService::visible($manager), 'id')), 'visible du responsable de l\'agence');
    ok(in_array($alert['id'], array_column(AlertService::visible($direction), 'id')), 'visible de la direction');
    ok(!in_array($alert['id'], array_column(AlertService::visible($other), 'id')), 'invisible d\'une autre agence');
    ok(!in_array($alert['id'], array_column(AlertService::visible($agent), 'id')), 'invisible du comptoir');
    Auth::actAs($manager);
    AlertService::acknowledge((int)$alert['id']);
    ok(Database::value('SELECT closed_at FROM alerts WHERE id = ?', [$alert['id']]) !== null, 'prise d\'acte ferme l\'alerte');
});

test('paiement mixte : plusieurs modes, un seul reçu, tout ou rien', function () {
    [$agent, $session, , $order] = fx_cash(5000);
    $client = (int)Database::value('SELECT client_id FROM orders WHERE id = ?', [$order]);
    $svc = new PaymentService();
    $r = $svc->recordMixed($client, [['method' => 'especes', 'amount' => 2000, 'reference' => ''], ['method' => 'orange', 'amount' => 1000, 'reference' => 'OM123'], ['method' => 'mtn', 'amount' => '']], orderId: $order);
    same(2, count($r['ids']));
    $rows = Database::all('SELECT receipt_no, split_group, method FROM payments WHERE order_id = ? ORDER BY id', [$order]);
    same($r['receipt'], $rows[0]['receipt_no']);
    same($rows[0]['receipt_no'], $rows[1]['receipt_no'], 'même reçu');
    ok($rows[0]['split_group'] !== null && $rows[0]['split_group'] === $rows[1]['split_group'], 'même groupe');
    same(3000, (int)Database::value('SELECT paid FROM orders WHERE id = ?', [$order]));
    // dépassement du solde : rien n'est enregistré
    throws(fn() => $svc->recordMixed($client, [['method' => 'especes', 'amount' => 1500], ['method' => 'orange', 'amount' => 1000]], orderId: $order), 'supérieur au solde');
    same(3000, (int)Database::value('SELECT paid FROM orders WHERE id = ?', [$order]), 'solde inchangé');
    same(2, (int)Database::value('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$order]), 'aucune ligne partielle');
    throws(fn() => $svc->recordMixed($client, [['method' => 'especes', 'amount' => 0]], orderId: $order), 'au moins un montant');
    // espèces : la caisse théorique tient compte des deux modes
    $exp = (new CashService())->expected($session);
    same(10000 + 2000, $exp['especes']);
    same(1000, $exp['orange']);
});

test('annulation d\'un encaissement : écriture inverse, autorisation d\'un responsable, motif', function () {
    [$agent, $session, $agency, $order] = fx_cash(5000);
    $client = (int)Database::value('SELECT client_id FROM orders WHERE id = ?', [$order]);
    $svc = new PaymentService();
    $pid = $svc->record($client, PaymentMethod::Especes, 3000, orderId: $order);
    $manager = fx_user('manager', $agency);

    // le comptoir seul ne peut pas autoriser
    throws(fn() => Authorizer::manager('', '', $agency), 'Autorisation refusée');
    throws(fn() => Authorizer::manager($manager['login'], 'mauvais', $agency), 'Autorisation refusée');
    throws(fn() => Authorizer::manager($agent['login'], 'x', $agency), 'Autorisation refusée');   // un agent n'est pas un responsable
    throws(fn() => Authorizer::manager(fx_user('manager', fx_agency())['login'], 'x', $agency), 'Autorisation refusée');   // responsable d'une autre agence
    $auth = Authorizer::manager($manager['login'], 'x', $agency);

    throws(fn() => $svc->reverse($pid, 'court', $auth), '8 caractères');
    $rid = $svc->reverse($pid, 'Client a changé d\'avis avant dépôt', $auth);
    $rev = Database::one('SELECT * FROM payments WHERE id = ?', [$rid]);
    same(-3000, (int)$rev['amount']);
    same('reversal', $rev['kind']);
    same($pid, (int)$rev['reverses_id']);
    same((int)$manager['id'], (int)$rev['authorised_by']);
    same(0, (int)Database::value('SELECT paid FROM orders WHERE id = ?', [$order]));
    same(10000, (new CashService())->expected($session)['especes'], 'la caisse théorique retrouve le fond');
    throws(fn() => $svc->reverse($pid, 'Deuxième annulation du même paiement', $auth), 'déjà été annulé');
    throws(fn() => $svc->reverse($rid, 'Annuler une annulation, impossible', $auth), 'Seul un encaissement');
    $log = Database::one("SELECT * FROM audit_log WHERE action = 'payment.reverse' AND entity_id = ?", [$pid]);
    ok($log !== null && str_contains((string)$log['old_value'], '3000') && str_contains((string)$log['new_value'], '0'), 'audit ancienne/nouvelle valeur');
    same(1, (int)Database::value('SELECT COUNT(*) FROM payments WHERE id = ?', [$pid]), 'l\'original n\'est jamais supprimé');
});

test('annulation : refusée une fois la commande remise, ou sans caisse ouverte pour rembourser', function () {
    [$agent, $session, $agency, $order] = fx_cash(4000);
    $client = (int)Database::value('SELECT client_id FROM orders WHERE id = ?', [$order]);
    $svc = new PaymentService();
    $pid = $svc->record($client, PaymentMethod::Especes, 4000, orderId: $order);
    $auth = Authorizer::manager(fx_user('direction', $agency)['login'], 'x', $agency);
    $cash = new CashService();
    $cash->close($session, $cash->expected($session), '');
    throws(fn() => $svc->reverse($pid, 'Remboursement demandé par le client', $auth), 'Ouvrez votre caisse');
    $cash->open((int)$agent['id'], $agency, 0, 'Caisse 2');
    Database::update('orders', ['status' => 'retire'], 'id = :id', ['id' => $order]);
    throws(fn() => $svc->reverse($pid, 'Remboursement demandé par le client', $auth), 'déjà remise');
});

test('remise : autorisée par un responsable, plafonnée, jamais sous le montant déjà encaissé', function () {
    [$agent, $session, $agency, $order] = fx_cash(10000);
    $svc = new OrderService();
    $manager = Authorizer::manager(fx_user('manager', $agency)['login'], 'x', $agency);
    $direction = Authorizer::manager(fx_user('direction', fx_agency())['login'], 'x');
    throws(fn() => $svc->applyDiscount($order, 500, 'court', $manager), '8 caractères');
    throws(fn() => $svc->applyDiscount($order, 0, 'Geste commercial pour retard', $manager), 'invalide');
    throws(fn() => $svc->applyDiscount($order, 3001, 'Geste commercial pour retard', $manager), 'plafond');   // 30 % de 10 000
    $svc->applyDiscount($order, 3000, 'Geste commercial pour retard', $manager);
    $o = Database::one('SELECT total, discount FROM orders WHERE id = ?', [$order]);
    same(7000, (int)$o['total']);
    same(3000, (int)$o['discount']);
    $adj = Database::one('SELECT * FROM order_adjustments WHERE order_id = ?', [$order]);
    same(10000, (int)$adj['old_total']);
    same(7000, (int)$adj['new_total']);
    same((int)$manager['id'], (int)$adj['authorised_by']);
    same((int)$agent['id'], (int)$adj['requested_by'], 'le demandeur est tracé en plus de l\'autorisant');
    $svc->applyDiscount($order, 3500, 'Accord de la direction, client fidèle', $direction);   // la direction n'est pas plafonnée
    same(3500, (int)Database::value('SELECT total FROM orders WHERE id = ?', [$order]));
    // encaissé 3 000 : une remise qui ramènerait le total sous 3 000 est refusée
    (new PaymentService())->record((int)Database::value('SELECT client_id FROM orders WHERE id = ?', [$order]), PaymentMethod::Especes, 3000, orderId: $order);
    throws(fn() => $svc->applyDiscount($order, 1000, 'Nouvelle remise sur commande payée', $direction), 'sous les sommes déjà encaissées');
    $log = Database::one("SELECT * FROM audit_log WHERE action = 'order.discount' AND entity_id = ? ORDER BY id", [$order]);
    ok($log !== null && $log['old_value'] === '{"total":10000}' && $log['new_value'] === '{"total":7000}', 'audit ancienne/nouvelle valeur');
});

test('Mobile Money : confirmation manuelle tracée, une seule fois', function () {
    [$agent, , $agency, $order] = fx_cash(6000);
    $client = (int)Database::value('SELECT client_id FROM orders WHERE id = ?', [$order]);
    $intent = Database::insert('payment_intents', ['reference' => 'T-' . bin2hex(random_bytes(5)), 'order_id' => $order, 'client_id' => $client, 'method' => 'orange', 'amount' => 6000, 'phone' => '237670000000', 'status' => 'PENDING', 'created_at' => now()]);
    $auth = Authorizer::manager(fx_user('manager', $agency)['login'], 'x', $agency);
    $svc = new MobileMoneyService();
    throws(fn() => $svc->confirmManually($intent, 'abc', $auth), 'référence');
    $pid = $svc->confirmManually($intent, 'OM240512.1234.A56', $auth);
    same(6000, (int)Database::value('SELECT paid FROM orders WHERE id = ?', [$order]));
    same('CONFIRMED', Database::value('SELECT status FROM payment_intents WHERE id = ?', [$intent]));
    ok(str_contains((string)Database::value('SELECT reference FROM payments WHERE id = ?', [$pid]), 'OM240512.1234.A56'));
    throws(fn() => $svc->confirmManually($intent, 'OM240512.1234.A56', $auth), 'déjà confirmé');
    same(0, (int)Database::value("SELECT COUNT(*) FROM payments WHERE method = 'orange' AND order_id = ? AND cash_session_id IS NOT NULL", [$order]), 'pas de caisse espèces pour du Mobile Money');
});

test('reçus : numéros uniques et état de caisse complet', function () {
    [$agent, $session, , $order] = fx_cash(5000);
    $client = (int)Database::value('SELECT client_id FROM orders WHERE id = ?', [$order]);
    $svc = new PaymentService();
    $a = $svc->record($client, PaymentMethod::Especes, 1000, orderId: $order);
    $b = $svc->record($client, PaymentMethod::Especes, 1000, orderId: $order);
    $ra = Database::value('SELECT receipt_no FROM payments WHERE id = ?', [$a]);
    $rb = Database::value('SELECT receipt_no FROM payments WHERE id = ?', [$b]);
    ok($ra !== $rb && preg_match('/^RC-\d{4}-\d{6}$/', (string)$ra), 'reçus distincts et bien formés');
    $st = (new CashService())->statement($session);
    same(2, count($st['payments']));
    same(12000, $st['expected']['especes']);
    ok($st['agent'] === $agent['name'] && $st['orders']['n'] >= 1);
});

test('transactions imbriquées : une exception annule le bloc interne sans toucher au reste', function () {
    $keep = fx_client();
    $inner = null;
    try {
        Database::transaction(function () use (&$inner): void {
            $inner = fx_client();
            Database::insert('settings', ['key_name' => 'zz.test', 'value' => '1', 'created_at' => now()]);
            throw new RuntimeException('échec simulé');
        });
    } catch (RuntimeException) {
    }
    same(0, (int)Database::value('SELECT COUNT(*) FROM clients WHERE id = ?', [$inner]), 'le client du bloc annulé a disparu');
    same(0, (int)Database::value("SELECT COUNT(*) FROM settings WHERE key_name = 'zz.test'"), 'les deux écritures sont annulées');
    same(1, (int)Database::value('SELECT COUNT(*) FROM clients WHERE id = ?', [$keep]), 'ce qui précède est conservé');
});
