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
