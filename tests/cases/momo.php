<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Services\MobileMoneyService;
use App\Services\Sungku;

function fx_intent(int $amount = 5000): array
{
    $c = fx_client();
    $o = fx_order(fx_agency(), $c, $amount);
    $ref = 'T-' . bin2hex(random_bytes(5));
    Database::insert('payment_intents', ['reference' => $ref, 'order_id' => $o, 'client_id' => $c, 'method' => 'orange', 'amount' => $amount, 'phone' => '237670000000', 'status' => 'PENDING', 'created_at' => now()]);
    return [$ref, $o];
}

test('momo : numéros camerounais normalisés, autres refusés', function () {
    same('237670123456', MobileMoneyService::normalizePhone('670 12 34 56'));
    same('237670123456', MobileMoneyService::normalizePhone('+237 6 70 12 34 56'));
    throws(fn() => MobileMoneyService::normalizePhone('0707070707'), 'invalide');
});

test('momo : signature du webhook (HMAC, rejeu, corps modifié)', function () {
    $orig = Config::all();
    Config::load(array_replace_recursive($orig, ['sungku' => ['webhook_secret' => 's3cret']]));
    $ts = (string)time();
    $body = '{"reference":"X","status":"CONFIRMED"}';
    $sig = 'sha256=' . hash_hmac('sha256', "$ts.$body", 's3cret');
    ok(Sungku::verifySignature($ts, $body, $sig));
    ok(!Sungku::verifySignature($ts, $body . ' ', $sig), 'corps modifié');
    $old = (string)(time() - 3600);
    ok(!Sungku::verifySignature($old, $body, 'sha256=' . hash_hmac('sha256', "$old.$body", 's3cret')), 'rejeu');
    ok(!Sungku::verifySignature($ts, $body, ''), 'signature vide');
    Config::load($orig);
});

test('momo : CONFIRMED crée un seul paiement même si le webhook est rejoué', function () {
    [$ref, $order] = fx_intent(5000);
    $svc = new MobileMoneyService();
    same('confirmed', $svc->handleWebhook(['reference' => $ref, 'status' => 'CONFIRMED', 'amount' => 5000]));
    same('duplicate', $svc->handleWebhook(['reference' => $ref, 'status' => 'CONFIRMED', 'amount' => 5000]));
    same(1, (int)Database::value('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$order]), 'un seul paiement');
    same(5000, (int)Database::value('SELECT paid FROM orders WHERE id = ?', [$order]), 'solde de la commande');
    same('CONFIRMED', Database::value('SELECT status FROM payment_intents WHERE reference = ?', [$ref]));
});

test('momo : montant incohérent, statut inconnu et référence inconnue sont ignorés', function () {
    [$ref, $order] = fx_intent(5000);
    $svc = new MobileMoneyService();
    same('ignored:amount-mismatch', $svc->handleWebhook(['reference' => $ref, 'status' => 'CONFIRMED', 'amount' => 1]));
    same('ignored:unknown-status', $svc->handleWebhook(['reference' => $ref, 'status' => 'COMPLETED']));
    same('ignored:unknown-reference', $svc->handleWebhook(['reference' => 'inexistante', 'status' => 'CONFIRMED']));
    same(0, (int)Database::value('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$order]));
});

test('momo : FAILED marque l\'intention en échec, un CONFIRMED tardif est quand même enregistré', function () {
    [$ref, $order] = fx_intent(3000);
    $svc = new MobileMoneyService();
    same('failed', $svc->handleWebhook(['reference' => $ref, 'status' => 'FAILED']));
    same('FAILED', Database::value('SELECT status FROM payment_intents WHERE reference = ?', [$ref]));
    same('confirmed', $svc->handleWebhook(['reference' => $ref, 'status' => 'CONFIRMED', 'amount' => 3000]), 'l\'argent a bougé');
    same(1, (int)Database::value('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$order]));
});

test('Mobile Money : le libellé affiché au client tient dans les 22 caractères de la passerelle', function () {
    same('Pressing PR2026000001', App\Services\MobileMoneyService::customerMessage('PR-2026-000001'));
    foreach (['PR-2026-000001', 'PR-2026-9999999', 'PR-2026-99999999', 'PR-2026-999999999999999', 'X', ''] as $n) {
        $m = App\Services\MobileMoneyService::customerMessage($n);
        ok(strlen($m) <= 22, "« $m » (" . strlen($m) . ' caractères)');
    }
    ok(str_ends_with(App\Services\MobileMoneyService::customerMessage('PR-2026-99999999'), 'PR202699999999'), 'le numéro est gardé en entier, c\'est le préfixe qui raccourcit');
});
