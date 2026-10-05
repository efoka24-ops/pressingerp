<?php
declare(strict_types=1);

use App\Controllers\OrderController;
use App\Core\Auth;
use App\Core\HttpException;
use App\Domain\Role;

test('agence : seuls Admin et Direction changent d\'agence à la connexion', function () {
    ok(Role::Admin->canSwitchAgency() && Role::Direction->canSwitchAgency());
    foreach ([Role::Comptoir, Role::Manager, Role::Atelier, Role::Qualite, Role::Livreur, Role::Commercial] as $r) {
        ok(!$r->canSwitchAgency(), $r->value);
    }
});

test('agence : comptoir et responsable sont limités à leur agence', function () {
    $a = fx_agency();
    Auth::actAs(fx_user('comptoir', $a));
    same($a, Auth::scopedAgencyId());
    Auth::actAs(fx_user('manager', $a));
    same($a, Auth::scopedAgencyId());
    Auth::actAs(fx_user('direction', $a));
    same(0, Auth::scopedAgencyId(), 'la direction voit le groupe');
    Auth::actAs(fx_user('atelier', $a));
    same(0, Auth::scopedAgencyId(), 'l\'atelier central traite toutes les agences');
});

test('agence : un comptoir ne peut pas ouvrir la commande d\'une autre agence', function () {
    $a = fx_agency();
    $b = fx_agency();
    $c = fx_client();
    $orderA = fx_order($a, $c);
    $orderB = fx_order($b, $c);
    $find = new ReflectionMethod(OrderController::class, 'find');
    $ctl = new OrderController();

    Auth::actAs(fx_user('comptoir', $a));
    same($orderA, (int)$find->invoke($ctl, $orderA)['id'], 'sa propre commande');
    $e = throws(fn() => $find->invoke($ctl, $orderB));
    ok($e instanceof HttpException && $e->status === 404, 'commande de l\'autre agence : 404 attendu');

    Auth::actAs(fx_user('direction', $a));
    same($orderB, (int)$find->invoke($ctl, $orderB)['id'], 'la direction voit tout');
});

test('agence : annulation, encaissement et Mobile Money refusés hors de son agence', function () {
    $a = fx_agency();
    $b = fx_agency();
    $orderB = fx_order($b, fx_client());
    $clientB = (int)\App\Core\Database::value('SELECT client_id FROM orders WHERE id = ?', [$orderB]);

    Auth::actAs(fx_user('manager', $a));
    throws(fn() => (new \App\Services\OrderService())->cancel($orderB, 'test'), 'introuvable');
    Auth::actAs(fx_user('comptoir', $a));
    throws(fn() => (new \App\Services\PaymentService())->record($clientB, \App\Domain\PaymentMethod::Orange, 1000, orderId: $orderB, viaGateway: true), 'introuvable');
    throws(fn() => (new \App\Services\MobileMoneyService())->initiate($orderB, \App\Domain\PaymentMethod::Orange, '670123456'), 'introuvable');

    Auth::actAs(fx_user('direction', $a));
    $id = (new \App\Services\PaymentService())->record($clientB, \App\Domain\PaymentMethod::Orange, 1000, orderId: $orderB, viaGateway: true);
    ok($id > 0, 'la direction encaisse toutes les agences');
});

test('agence : le responsable d\'agence n\'a pas les modules non filtrables par agence', function () {
    foreach (['bi', 'commercial', 'marketing'] as $m) {
        ok(!Role::Manager->can($m), $m);
    }
    ok(Role::Manager->can('orders', 'validate') && Role::Manager->can('cash') && Role::Manager->can('cockpit'));
});
