<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Services\AlertService;
use App\Services\OrderService;
use App\Services\ReminderService;
use App\Services\SettingsService;

/** Commande prête depuis $days jours (pleins) à l'instant $now. @return array{order:int,client:int,agency:int} */
function fx_ready(int $now, float $days, array $client = [], array $order = []): array
{
    $agency = fx_agency();
    Auth::actAs(fx_user('comptoir', $agency));
    $c = fx_msg_client($client);
    $o = fx_order($agency, $c, 5000);
    Database::update('orders', array_merge(['status' => 'pret', 'ready_at' => date('Y-m-d H:i:s', (int)($now - $days * 86400))], $order), 'id = :id', ['id' => $o]);
    $g = fx_garment($o);
    Database::update('garments', ['step' => 'pret', 'status' => 'termine'], 'id = :id', ['id' => $g]);
    Database::insert('quality_checks', ['garment_id' => $g, 'user_id' => Auth::id(), 'result' => 'conforme', 'created_at' => date('Y-m-d H:i:s', $now)]);
    return ['order' => $o, 'client' => $c, 'agency' => $agency];
}

function reminder_events(int $order): array
{
    return array_column(Database::all("SELECT event FROM messages WHERE order_id = ? AND event LIKE 'reminder%' ORDER BY id", [$order]), 'event');
}

test('relances : calendrier J+2, J+7, J+15 (veille, jour J, lendemain)', function () {
    $now = time();
    $a = fx_ready($now, 1.9);    // pas encore 2 jours pleins
    $b = fx_ready($now, 2.0);
    ReminderService::run($now);
    same([], reminder_events($a['order']), 'avant J+2 : rien');
    same(['reminder1'], reminder_events($b['order']), 'à J+2 : 1er rappel');
    ReminderService::run($now + 3600);
    same(['reminder1'], reminder_events($b['order']), 'idempotent : pas de doublon');
    ReminderService::run($now + 5 * 86400);
    same(['reminder1', 'reminder2'], reminder_events($b['order']), 'J+7');
    ReminderService::run($now + 13 * 86400);
    same(['reminder1', 'reminder2', 'reminder3'], reminder_events($b['order']), 'J+15');
    ReminderService::run($now + 40 * 86400);
    same(3, count(reminder_events($b['order'])), 'jamais plus de trois relances');
});

test('relances : plusieurs paliers dépassés d\'un coup => une seule relance, la plus récente', function () {
    $now = time();
    $o = fx_ready($now, 9);
    $r = ReminderService::run($now);
    same(['reminder2'], reminder_events($o['order']));
    same(2, (int)Database::value('SELECT COUNT(*) FROM order_reminders WHERE order_id = ?', [$o['order']]), 'palier 1 marqué sauté');
    ok($r['skipped'] >= 1);
});

test('relances : le texte annonce l\'ancienneté et le solde à payer', function () {
    $now = time();
    $o = fx_ready($now, 3, [], ['paid' => 2000]);
    ReminderService::run($now);
    $body = (string)Database::value("SELECT body FROM messages WHERE order_id = ? AND event = 'reminder1'", [$o['order']]);
    ok(str_contains($body, '3 jours') && str_contains($body, 'Reste à payer') && str_contains($body, '3'), $body);
    $paid = fx_ready($now, 3, [], ['paid' => 5000]);
    ReminderService::run($now);
    ok(!str_contains((string)Database::value("SELECT body FROM messages WHERE order_id = ? AND event = 'reminder1'", [$paid['order']]), 'Reste à payer'), 'rien à payer : pas de solde');
});

test('relances : annulées au retrait, et jamais pour une commande remise, annulée ou en livraison', function () {
    $now = time();
    $o = fx_ready($now, 3, [], ['paid' => 5000]);
    ReminderService::run($now);
    same('en_attente', Database::value("SELECT status FROM messages WHERE order_id = ? AND event = 'reminder1'", [$o['order']]));
    (new OrderService())->pickup($o['order'], \App\Domain\PaymentMethod::Especes);
    same('annule', Database::value("SELECT status FROM messages WHERE order_id = ? AND event = 'reminder1'", [$o['order']]), 'relance en file annulée au retrait');

    $done = fx_ready($now, 5, [], ['status' => 'retire']);
    $cancelled = fx_ready($now, 5, [], ['status' => 'annule']);
    $delivery = fx_ready($now, 5, [], ['delivery_address' => 'Bastos, rue 1.234, villa 5']);
    ReminderService::run($now);
    same([], reminder_events($done['order']));
    same([], reminder_events($cancelled['order']));
    same([], reminder_events($delivery['order']), 'une livraison suit son propre circuit');
});

test('relances : paliers paramétrables par l\'administrateur', function () {
    $now = time();
    Auth::actAs(fx_user('admin', fx_agency()));
    SettingsService::set('reminder.days', '1,4', 'Test des paliers');
    same([1, 4], ReminderService::levels());
    $o = fx_ready($now, 1.2);
    ReminderService::run($now);
    same(['reminder1'], reminder_events($o['order']));
    throws(fn() => SettingsService::set('reminder.days', 'abc', 'Valeur invalide'), 'Format attendu');
    throws(fn() => SettingsService::set('reminder.days', '2, 7', 'Espaces'), 'Format attendu');
    SettingsService::set('reminder.days', '15,2,7,7,30', 'Désordre et doublons');
    same([2, 7, 15], ReminderService::levels(), 'triés, sans doublon, trois paliers au plus');
});

test('relances SE12 : alerte du responsable au seuil, client injoignable signalé, fermée au retrait', function () {
    $now = time();
    $ok = fx_ready($now, 16, [], ['paid' => 5000]);
    $lost = fx_ready($now, 16, ['phone' => (string)random_int(1000, 99999)]);
    $young = fx_ready($now, 10);
    Auth::actAs(fx_user('admin', fx_agency()));
    SettingsService::set('reminder.manager_after', '15', 'Test du seuil');
    $direct = ReminderService::managerAlerts($now);
    ok(isset($direct['unc:' . $ok['order']]), 'managerAlerts vide : after=' . json_encode(SettingsService::get('reminder.manager_after')) . ' row=' . json_encode(Database::one('SELECT status, ready_at, delivery_address FROM orders WHERE id = ?', [$ok['order']])) . ' now=' . date('Y-m-d H:i:s', $now) . ' ' . json_encode(array_keys($direct)) . ' rule=' . json_encode(AlertService::rule('uncollected')));
    AlertService::tick($now);
    $a = Database::one("SELECT * FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL", ['unc:' . $ok['order']]);
    ok($a !== null && str_contains($a['message'], '16 jours') && str_contains($a['message'], 'Contacter le client'), 'alerte au seuil : ' . json_encode($a));
    same('manager', $a['target_role']);
    $b = Database::one("SELECT * FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL", ['unc:' . $lost['order']]);
    ok($b !== null && str_contains($b['message'], 'injoignable'), 'client sans coordonnée valide : injoignable');
    same(null, Database::value("SELECT id FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL", ['unc:' . $young['order']]), 'avant le seuil : pas d\'alerte');
    (new OrderService())->pickup($ok['order'], \App\Domain\PaymentMethod::Especes);
    same(null, Database::value("SELECT id FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL", ['unc:' . $ok['order']]), 'fermée au retrait');
});

test('non retirés : tableau par tranche (nombre, valeur, ancienneté), limité à l\'agence', function () {
    $now = time();
    $a = fx_ready($now, 1);
    $b = fx_ready($now, 5);
    $c = fx_ready($now, 20);
    $o = ReminderService::overview($a['agency'], $now);
    same(1, array_sum(array_column($o['buckets'], 'n')), 'une seule commande dans cette agence');
    same(1, $o['buckets'][0]['n']);
    $o = ReminderService::overview($c['agency'], $now);
    same(1, $o['buckets'][3]['n'], 'plus de 15 jours');
    same(5000, $o['buckets'][3]['value']);
    $o = ReminderService::overview($b['agency'], $now);
    same(1, $o['buckets'][1]['n']);
    same(5000, $o['buckets'][1]['due']);
});

test('pages : tableau des non retirés', function () {
    $now = time();
    $f = fx_ready($now, 9);
    ReminderService::run($now);
    Auth::actAs(fx_user('manager', $f['agency']));
    $html = render_page('/commandes/non-retirees');
    ok(str_contains($html, 'Commandes non retirées') && str_contains($html, 'Relance n° 2') && str_contains($html, '8 à 15 jours'));
});
