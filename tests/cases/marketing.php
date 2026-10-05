<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Services\ConsentService;
use App\Services\MarketingService;
use App\Services\ScenarioService;
use App\Services\SettingsService;

/** Client dont la dernière commande date de $idleDays jours, créé il y a $ageDays jours. */
function fx_seg_client(?int $idleDays, int $ageDays = 400, int $orders = 1, int $total = 5000, array $over = []): int
{
    $id = fx_msg_client(array_merge(['created_at' => date('Y-m-d H:i:s', strtotime("-$ageDays days"))], $over));
    $agency = fx_agency();
    if ($idleDays !== null) {
        for ($i = 0; $i < $orders; $i++) {
            $o = fx_order($agency, $id, $total);
            Database::update('orders', ['created_at' => date('Y-m-d H:i:s', strtotime('-' . ($idleDays + $i) . ' days -1 hour'))], 'id = :id', ['id' => $o]);
        }
    }
    return $id;
}

function seg_of(int $client): string
{
    foreach ((new MarketingService())->segments() as $name => $ids) {
        if (in_array($name, ['tous', 'pros', 'debiteurs', 'fort_panier', 'points'], true)) {
            continue;
        }
        if (in_array($client, $ids, true)) {
            return $name;
        }
    }
    return '?';
}

function seg_in(string $segment, int $client): bool
{
    return in_array($client, (new MarketingService())->segments()[$segment] ?? [], true);
}

test('segments : bornes de jours (nouveau, à risque dès 45 j, perdu au-delà de 90 j)', function () {
    same('nouveaux', seg_of(fx_seg_client(2, 29)), 'créé il y a 29 j');
    same('reguliers', seg_of(fx_seg_client(44, 400, 3)), '44 j sans commande, 3 commandes');
    same('a_risque', seg_of(fx_seg_client(45, 400, 3)), '45 j : à risque');
    same('a_risque', seg_of(fx_seg_client(90, 400, 3)), '90 j : encore à risque');
    same('perdus', seg_of(fx_seg_client(91, 400, 3)), '91 j : perdu');
    same('a_verifier', seg_of(fx_seg_client(null, 200)), 'jamais commandé, pas nouveau');
    same('nouveaux', seg_of(fx_seg_client(null, 3)), 'jamais commandé mais nouveau');
});

test('segments : réguliers et occasionnels selon le nombre de commandes sur 12 mois', function () {
    same('occasionnels', seg_of(fx_seg_client(10, 400, 2)));
    same('reguliers', seg_of(fx_seg_client(10, 400, 3)));
});

test('segments : VIP marqué ou par chiffre d\'affaires annuel, fort panier, débiteur, points', function () {
    same('vip', seg_of(fx_seg_client(10, 400, 1, 5000, ['is_vip' => 1])));
    $threshold = (int)SettingsService::get('vip.annual_threshold');
    same('vip', seg_of(fx_seg_client(10, 400, 1, $threshold)), 'seuil atteint');
    ok(seg_of(fx_seg_client(10, 400, 1, $threshold - 1)) !== 'vip', 'seuil − 1');
    $big = fx_seg_client(10, 400, 2, (int)SettingsService::get('seg.basket_high'));
    ok(seg_in('fort_panier', $big) && !seg_in('fort_panier', fx_seg_client(10, 400, 2, 1000)));
    $debtor = fx_seg_client(10, 400, 1, 5000);
    Database::update('orders', ['on_account' => 1], 'client_id = :c', ['c' => $debtor]);
    ok(seg_in('debiteurs', $debtor));
    $pts = fx_seg_client(10, 400, 1, 5000, ['loyalty_points' => (int)SettingsService::get('seg.points_notify')]);
    ok(seg_in('points', $pts) && !seg_in('points', fx_seg_client(10, 400, 1, 5000, ['loyalty_points' => 1])));
    ok(seg_in('pros', fx_seg_client(10, 400, 1, 5000, ['type' => 'pro'])));
});

test('segments : les seuils sont des paramètres, pas des constantes (A8)', function () {
    Auth::actAs(fx_user('admin', fx_agency()));
    $c = fx_seg_client(60, 400, 3);
    same('a_risque', seg_of($c));
    SettingsService::set('seg.at_risk_days', '70', 'Test : seuil à risque relevé');
    same('reguliers', seg_of($c), '60 j < 70 j');
    SettingsService::set('seg.at_risk_days', '45', 'Test : retour au seuil habituel');
    ok(array_key_exists('seg.validated', SettingsService::DEFINITIONS) && SettingsService::DEFINITIONS['seg.validated'][2] === 0, 'seuils « à vérifier » tant que la direction ne les valide pas');
});

test('scénarios RG18 : aucun message sans consentement marketing, un seul par client dans le délai', function () {
    Auth::actAs(fx_user('marketing', fx_agency()));
    $now = time();
    $with = fx_seg_client(46, 400, 3);
    $without = fx_seg_client(46, 400, 3);
    ConsentService::set($with, 'sms', true, 'test');
    ScenarioService::update('reactivation', 'Bonjour {prenom}, revenez nous voir cette semaine !', 7, true);
    $r = ScenarioService::run($now);
    same('Bonjour Marie, revenez nous voir cette semaine !', Database::value("SELECT body FROM messages WHERE client_id = ? AND event = 'scenario_reactivation'", [$with]));
    same(0, (int)Database::value("SELECT COUNT(*) FROM messages WHERE client_id = ? AND event = 'scenario_reactivation'", [$without]), 'pas de consentement : rien');
    same('marketing', Database::value("SELECT purpose FROM messages WHERE client_id = ? AND event = 'scenario_reactivation'", [$with]));
    ok($r['skipped'] >= 1);
    ScenarioService::run($now + 86400);
    same(1, (int)Database::value("SELECT COUNT(*) FROM messages WHERE client_id = ? AND event = 'scenario_reactivation'", [$with]), 'pas de doublon dans le délai');
    ScenarioService::run($now + 8 * 86400);
    same(2, (int)Database::value("SELECT COUNT(*) FROM messages WHERE client_id = ? AND event = 'scenario_reactivation'", [$with]), 'nouvel envoi après le délai');
    ConsentService::set($with, 'sms', false, 'test');
    ScenarioService::run($now + 20 * 86400);
    same(2, (int)Database::value("SELECT COUNT(*) FROM messages WHERE client_id = ? AND event = 'scenario_reactivation'", [$with]), 'consentement retiré : plus rien');
    ScenarioService::update('reactivation', 'Bonjour {prenom}, revenez nous voir cette semaine !', 90, false);
});

test('scénarios : désactivés, ils n\'envoient rien ; réglages contrôlés', function () {
    Auth::actAs(fx_user('marketing', fx_agency()));
    $c = fx_seg_client(60, 400, 3);
    ConsentService::set($c, 'sms', true, 'test');
    ScenarioService::update('reactivation', 'Message de réactivation de test', 90, false);
    ScenarioService::run();
    same(0, (int)Database::value("SELECT COUNT(*) FROM messages WHERE client_id = ? AND event LIKE 'scenario_%'", [$c]));
    throws(fn() => ScenarioService::update('reactivation', 'court', 90, true), 'Message');
    throws(fn() => ScenarioService::update('reactivation', 'Message de réactivation de test', 3, true), 'Délai');
    throws(fn() => ScenarioService::update('inconnu', 'Message de réactivation de test', 90, true), 'introuvable');
});

test('scénario fidélité : cible les clients qui ont des points à utiliser', function () {
    Auth::actAs(fx_user('marketing', fx_agency()));
    $pts = (int)SettingsService::get('seg.points_notify');
    $c = fx_seg_client(10, 400, 1, 5000, ['loyalty_points' => $pts + 5]);
    ConsentService::set($c, 'sms', true, 'test');
    ScenarioService::update('fidelite', 'Bonjour {prenom}, vous avez {points} points à utiliser !', 180, true);
    ScenarioService::run();
    same('Bonjour Marie, vous avez ' . ($pts + 5) . ' points à utiliser !', Database::value("SELECT body FROM messages WHERE client_id = ? AND event = 'scenario_fidelite'", [$c]));
    ScenarioService::update('fidelite', 'Bonjour {prenom}, vous avez {points} points à utiliser !', 180, false);
});

test('VIP automatique : le seuil de chiffre d\'affaires passe le client en VIP, tracé, sans jamais rétrograder', function () {
    Auth::actAs(fx_user('marketing', fx_agency()));
    $threshold = (int)SettingsService::get('vip.annual_threshold');
    $c = fx_seg_client(10, 400, 1, $threshold);
    $below = fx_seg_client(10, 400, 1, $threshold - 1);
    $manual = fx_seg_client(10, 400, 1, 1000, ['is_vip' => 1]);
    ScenarioService::promoteVip();
    same(1, (int)Database::value('SELECT is_vip FROM clients WHERE id = ?', [$c]));
    same(0, (int)Database::value('SELECT is_vip FROM clients WHERE id = ?', [$below]));
    same(1, (int)Database::value('SELECT is_vip FROM clients WHERE id = ?', [$manual]), 'VIP marqué à la main conservé');
    same(1, (int)Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'client.vip_auto' AND entity_id = ?", [$c]));
    same(0, ScenarioService::promoteVip(), 'idempotent : personne de plus au second passage');
});

test('pages : marketing avec bandeau « seuils à vérifier » et scénarios', function () {
    Auth::actAs(fx_user('marketing', fx_agency()));
    $html = render_page('/marketing');
    ok(str_contains($html, 'Scénarios automatiques') && str_contains($html, 'Réactivation') && str_contains($html, 'Occasionnels'));
    ok(str_contains($html, 'à vérifier'), 'bandeau tant que les seuils ne sont pas validés');
});
