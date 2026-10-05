<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Domain\PaymentMethod;
use App\Services\AlertService;
use App\Services\CashService;
use App\Services\PaymentService;

/** Rend une page GET comme le ferait le navigateur et renvoie son HTML (les redirections ne sont pas suivies). */
function render_page(string $uri): string
{
    static $router = null;
    $router ??= require BASE_PATH . '/app/routes.php';
    $_SERVER['REQUEST_URI'] = $uri;
    $_GET = [];
    if ($q = parse_url($uri, PHP_URL_QUERY)) {
        parse_str($q, $_GET);
    }
    ob_start();
    try {
        $router->dispatch('GET', parse_url($uri, PHP_URL_PATH) ?: '/');
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

test('pages : fiche commande, reçu, ticket, état de caisse et alertes se rendent avec des données réelles', function () {
    $agency = fx_agency();
    $agent = fx_user('comptoir', $agency);
    Auth::actAs($agent);
    $cash = new CashService();
    $cash->open((int)$agent['id'], $agency, 5000, 'Caisse pages');
    $session = $cash->current((int)$agent['id']);
    $client = fx_client();
    $order = fx_order($agency, $client, 8000);
    $g = fx_garment($order);
    Database::update('orders', ['promised_at' => date('Y-m-d H:i:s', time() - 7200)], 'id = :id', ['id' => $order]);

    $r = (new PaymentService())->recordMixed($client, [['method' => 'especes', 'amount' => 2000], ['method' => 'orange', 'amount' => 1000, 'reference' => 'OM1']], orderId: $order);
    Database::insert('payment_intents', ['reference' => 'T-' . bin2hex(random_bytes(5)), 'order_id' => $order, 'client_id' => $client, 'method' => 'mtn', 'amount' => 5000, 'phone' => '237670000000', 'status' => 'PENDING', 'created_at' => now()]);
    AlertService::tick();

    // Comptoir : sa fiche commande propose paiement mixte, remise, annulation, Mobile Money (avec champs d'autorisation)
    $html = render_page("/commandes/$order");
    ok(str_contains($html, 'Encaisser (un seul reçu)') && str_contains($html, 'Mobile Money') && str_contains($html, 'name="auth_login"'), 'fiche commande du comptoir');
    ok(str_contains($html, 'mixte') && str_contains($html, $r['receipt']), 'paiement mixte et numéro de reçu affichés');
    ok(str_contains($html, 'Annuler un encaissement') && str_contains($html, 'Confirmer manuellement'), 'annulation et confirmation manuelle proposées');

    $receipt = render_page('/paiements/' . $r['ids'][0] . '/recu');
    ok(str_contains($receipt, 'REÇU DE PAIEMENT') && str_contains($receipt, $r['receipt']) && str_contains($receipt, 'Orange Money') && str_contains($receipt, 'RESTE À PAYER'), 'reçu des deux lignes');
    $ticket = render_page("/commandes/$order/ticket");
    ok(str_contains($ticket, 'TOTAL TTC') && str_contains($ticket, 'TVA'), 'ticket');
    $labels = render_page("/commandes/$order/etiquettes");
    ok(str_contains($labels, 'qrcode'), 'étiquettes');

    $statement = render_page('/caisse/' . $session['id'] . '/etat');
    ok(str_contains($statement, 'État de caisse') && str_contains($statement, 'aucune tolérance') && str_contains($statement, $r['receipt']), 'état de caisse');

    // Responsable : il voit les alertes et la page des commandes à risque
    Auth::actAs(fx_user('manager', $agency));
    $alerts = render_page('/alertes');
    ok(str_contains($alerts, 'Alertes') && str_contains($alerts, 'Commandes à risque') && str_contains($alerts, 'en retard'), 'centre d\'alertes');
    ok(str_contains(render_page('/caisse'), 'Caisse'), 'page caisse du responsable');
    ok(str_contains(render_page('/commandes'), 'Alertes'), 'cloche d\'alertes dans la mise en page');

    // Administrateur : pages d'administration nouvelles
    Auth::actAs(fx_user('admin', $agency));
    foreach (['/admin/alertes', '/admin/postes', '/admin/parcours', '/tarifs', '/qualite/derogations', '/qualite/sinistres', '/admin'] as $uri) {
        ok(strlen(render_page($uri)) > 500, "page $uri");
    }
});

test('pages : un comptoir ne voit pas la caisse d\'une autre agence', function () {
    $agencyA = fx_agency();
    $a = fx_user('comptoir', $agencyA);
    Auth::actAs($a);
    $cash = new CashService();
    $cash->open((int)$a['id'], $agencyA, 0, 'Caisse A');
    $session = $cash->current((int)$a['id']);
    Auth::actAs(fx_user('comptoir', fx_agency()));
    $e = throws(fn() => render_page('/caisse/' . $session['id'] . '/etat'));
    ok($e instanceof App\Core\HttpException && $e->status === 403, 'état de caisse d\'un autre agent refusé');
});
