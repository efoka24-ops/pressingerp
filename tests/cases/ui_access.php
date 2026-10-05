<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Domain\Module;
use App\Domain\Role;

/**
 * Contrôle d'interface : chaque profil ne doit voir que des liens, boutons et formulaires qu'il a le droit d'utiliser.
 * Pour chaque profil, on affiche ses pages (menu, fiches avec données réelles) et on vérifie que chaque lien/formulaire
 * interne visible mène à une route que ce profil peut ouvrir.
 */

/** Droit exigé par une route pour cette méthode, ou null si publique/connectée. @return ?array{0:string,1:string,2:string} [module, action, route] */
function ui_required(array $routes, string $method, string $path): ?array
{
    foreach ($routes as $r) {
        if ($r['method'] !== $method || !preg_match($r['regex'], $path)) {
            continue;
        }
        if ($r['perm'] === null || $r['perm'] === 'auth') {
            return null;
        }
        [$module, $action] = str_contains($r['perm'], ':') ? explode(':', $r['perm'], 2) : [$r['perm'], null];
        $hasParams = str_contains($r['regex'], '(?P<');
        $action ??= $method === 'GET' ? 'read' : ($hasParams ? 'update' : 'create');
        return [$module, $action, $r['regex']];
    }
    return null;
}

/** @return list<array{0:string,1:string}> [méthode, chemin] des liens et formulaires internes d'une page */
function ui_targets(string $html): array
{
    $out = [];
    if (preg_match_all('/<a\s[^>]*href="([^"#]+)"/i', $html, $m)) {
        foreach ($m[1] as $h) {
            $out[] = ['GET', $h];
        }
    }
    if (preg_match_all('/<form\s[^>]*action="([^"]+)"/i', $html, $m)) {
        foreach ($m[1] as $h) {
            $out[] = ['POST', $h];
        }
    }
    $clean = [];
    foreach ($out as [$method, $h]) {
        $h = html_entity_decode($h);
        if (!str_starts_with($h, '/') || str_starts_with($h, '//') || preg_match('#^/(assets|uploads|logout|login|suivi)(/|$|\?)#', $h)) {
            continue;
        }
        $clean[] = [$method, rtrim((string)parse_url($h, PHP_URL_PATH), '/') ?: '/'];
    }
    return array_values(array_unique($clean, SORT_REGULAR));
}

test('interface : chaque profil ne voit que des liens, boutons et formulaires qu\'il peut utiliser', function () {
    $f = fx_delivery(6000, 1000);
    $routes = (require BASE_PATH . '/app/routes.php')->routes();
    $client = $f['client'];
    $problems = [];
    foreach (Role::cases() as $role) {
        $user = fx_user($role->value, $f['agency']);
        Auth::actAs($user);
        $pages = [$role->home(), '/alertes'];
        foreach (Module::ALL as $code => [, , $href]) {
            if ($role->can($code)) {
                $pages[] = $href;
            }
        }
        // Fiches avec données réelles, uniquement si le profil peut les ouvrir
        foreach ([['orders', '/commandes/' . $f['order']], ['clients', '/clients/' . $client], ['delivery', '/livraisons/' . $f['delivery']]] as [$mod, $url]) {
            if ($role->can($mod)) {
                $pages[] = $url;
            }
        }
        foreach (array_unique($pages) as $page) {
            try {
                $html = render_page($page);
            } catch (Throwable $e) {
                if ($e instanceof App\Core\HttpException && in_array($e->getCode(), [403, 404], true)) {
                    continue;   // page hors périmètre ou sans donnée pour ce profil : couvert par les tests d'accès
                }
                throw new RuntimeException("{$role->value} · $page : " . $e->getMessage());
            }
            foreach (ui_targets($html) as [$method, $path]) {
                $need = ui_required($routes, $method, $path);
                if ($need && !$role->can($need[0], $need[1])) {
                    $problems[] = "{$role->value} voit sur " . preg_replace('#/\d+#', '/N', $page) . ' : ' . $method . ' ' . preg_replace('#/\d+#', '/N', $path) . " (exige {$need[0]}:{$need[1]})";
                }
            }
        }
    }
    ok($problems === [], "éléments visibles mais interdits :\n  " . implode("\n  ", array_unique($problems)));
});
