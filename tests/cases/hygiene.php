<?php
declare(strict_types=1);

/**
 * Pièges PHP déjà rencontrés dans ce projet.
 * Dans un fichier à espace de noms, `catch (Throwable $e)` sans antislash ne capture rien (la classe `App\X\Throwable`
 * n'existe pas) : un retour arrière ou une gestion d'erreur ne se déclenche alors jamais, sans aucun message.
 */
test('hygiène : aucune classe d\'exception globale utilisée sans antislash dans les fichiers à espace de noms', function () {
    $watched = ['Throwable', 'RuntimeException', 'DomainException', 'PDOException', 'InvalidArgumentException', 'Exception', 'ReflectionMethod', 'ArgumentCountError', 'Error'];
    $bad = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        $src = (string)file_get_contents($f->getPathname());
        if (!preg_match('/^namespace /m', $src)) {
            continue;
        }
        $tokens = token_get_all($src);
        foreach ($tokens as $i => $t) {
            if (!is_array($t) || $t[0] !== T_STRING || !in_array($t[1], $watched, true)) {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            $qualified = is_array($before) && $before[0] === T_NS_SEPARATOR;
            $member = is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
            $imported = false;
            for ($j = $i - 1; $j >= 0 && $j > $i - 4; $j--) {
                $imported = $imported || (is_array($tokens[$j]) && $tokens[$j][0] === T_USE);
            }
            if (!$qualified && !$member && !$imported) {
                $bad[] = str_replace(BASE_PATH, '', $f->getPathname()) . ':' . $t[2] . ' ' . $t[1];
            }
        }
    }
    same([], $bad, 'classes globales sans antislash');
});

test('hygiène : les transactions imbriquées annulent seulement leur bloc', function () {
    $keep = fx_client();
    $inner = null;
    try {
        App\Core\Database::transaction(function () use (&$inner): void {
            $inner = fx_client();
            throw new RuntimeException('échec simulé');
        });
    } catch (RuntimeException) {
    }
    same(0, (int)App\Core\Database::value('SELECT COUNT(*) FROM clients WHERE id = ?', [$inner]), 'le bloc annulé a disparu');
    same(1, (int)App\Core\Database::value('SELECT COUNT(*) FROM clients WHERE id = ?', [$keep]), 'ce qui précède est conservé');
});

test('hygiène : chaque route pointe vers un contrôleur et une méthode qui existent, avec un droit connu', function () {
    $router = require BASE_PATH . '/app/routes.php';
    $modules = array_keys(App\Domain\Module::ALL);
    $actions = array_keys(App\Domain\Role::ACTIONS);
    $bad = [];
    foreach ($router->routes() as $r) {
        [$class, $action] = $r['handler'];
        if (!class_exists($class) || !method_exists($class, $action)) {
            $bad[] = $r['method'] . ' ' . $r['regex'] . ' -> ' . $class . '::' . $action;
        }
        if ($r['perm'] !== null && $r['perm'] !== 'auth') {
            [$module, $act] = array_pad(explode(':', $r['perm'], 2), 2, null);
            if (!in_array($module, $modules, true) || ($act !== null && !in_array($act, $actions, true))) {
                $bad[] = $r['method'] . ' ' . $r['regex'] . ' : droit inconnu « ' . $r['perm'] . ' »';
            }
        }
    }
    same([], $bad, 'routes cassées');
    ok(count($router->routes()) > 100, 'toutes les routes sont chargées');
});
