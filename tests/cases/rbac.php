<?php
declare(strict_types=1);

use App\Controllers\OrderController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Domain\Role;

test('rbac : droits du comptoir', function () {
    $r = Role::Comptoir;
    ok($r->can('orders', 'create') && $r->can('orders', 'update') && $r->can('clients', 'read') && $r->can('cash', 'create'));
    ok(!$r->can('orders', 'validate'), 'le comptoir ne valide pas (annulation)');
    ok(!$r->can('orders', 'delete') && !$r->can('commercial') && !$r->can('admin') && !$r->can('cockpit') && !$r->can('bi'));
});

test('rbac : droits de la production, de la qualité et de la livraison', function () {
    ok(Role::Atelier->can('production', 'update') && !Role::Atelier->can('production', 'validate') && !Role::Atelier->can('orders'));
    ok(Role::Qualite->can('quality', 'validate') && !Role::Qualite->can('cash') && !Role::Qualite->can('quality', 'delete'));
    ok(Role::Livreur->can('orders') && !Role::Livreur->can('orders', 'update') && !Role::Livreur->can('cash'));
    ok(Role::Superviseur->can('production', 'validate') && !Role::Superviseur->can('cash'));
});

test('rbac : droits de la direction et de l\'administrateur', function () {
    ok(Role::Direction->can('cockpit') && Role::Direction->can('commercial', 'validate') && Role::Direction->can('admin'));
    ok(!Role::Direction->can('admin', 'update') && !Role::Direction->can('orders', 'delete'));
    ok(Role::Admin->can('admin', 'delete') && Role::Admin->can('cash', 'validate'));
    ok(Role::Commercial->can('commercial', 'validate') && !Role::Commercial->can('orders') && !Role::Commercial->can('production'));
    ok(Role::Marketing->can('marketing', 'validate') && !Role::Marketing->can('cash'));
});

test('rbac : chaque rôle a un accueil et au moins un module', function () {
    foreach (Role::cases() as $r) {
        ok(str_starts_with($r->home(), '/') && $r->modules() !== [], $r->value);
    }
});

test('rbac : un refus lève 403 et est journalisé', function () {
    $u = fx_user('atelier', fx_agency());
    Auth::actAs($u);
    $e = throws(fn() => Auth::authorize('orders', 'GET', false));
    ok($e instanceof HttpException && $e->status === 403, 'attendu 403, obtenu ' . get_class($e) . ' : ' . $e->getMessage());
    $a = Database::one("SELECT * FROM audit_log WHERE action = 'access.denied' ORDER BY id DESC LIMIT 1");
    ok($a !== null && (int)$a['user_id'] === (int)$u['id'], 'refus non journalisé');
});

test('rbac : l\'action est déduite de la méthode HTTP', function () {
    $u = fx_user('comptoir', fx_agency());
    Auth::actAs($u);
    Auth::authorize('orders', 'GET', false);          // lecture
    Auth::authorize('orders', 'POST', false);         // création
    Auth::authorize('orders', 'POST', true);          // modification
    $e = throws(fn() => Auth::authorize('orders:validate', 'POST', true));
    ok($e instanceof HttpException, 'obtenu ' . get_class($e) . ' : ' . $e->getMessage());
    same(403, $e->status);
});

test('connexion : un échec est refusé et journalisé', function () {
    ok(Auth::attempt('inconnu.' . bin2hex(random_bytes(3)), 'mauvais') === false);
    $a = Database::one("SELECT * FROM audit_log WHERE action = 'auth.failed' ORDER BY id DESC LIMIT 1");
    ok($a !== null, 'échec de connexion non journalisé');
});
