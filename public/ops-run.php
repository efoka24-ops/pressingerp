<?php
declare(strict_types=1);

/**
 * Tâches de maintenance à usage unique (hébergement sans SSH), protégées par jeton.
 * POST /ops-run.php?token=...&task=selftest|backup
 * Téléversé puis supprimé par ops/deploy.sh run <tâche> ; le jeton est vidé ensuite.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Services\Backup;

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);

$expected = (string)Config::get('migrate_token', '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $expected === '' || !hash_equals($expected, (string)($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit("Not found\n");
}

try {
    switch ($_GET['task'] ?? '') {
        case 'selftest':
            require BASE_PATH . '/tests/run.php';
            break;
        case 'backup':
            $r = Backup::run();
            echo implode("\n", $r['log']), "\n", $r['verified'] ? 'OK' : 'ATTENTION : sauvegarde non vérifiée', ' — ', $r['file'], ' (', $r['size'], " octets)\n";
            break;
        case 'restore-test':
            $r = Backup::restoreTest();
            echo implode("\n", $r['log']), "\n", implode("\n", $r['diffs']), "\n", $r['ok'] ? 'RESTAURATION OK' : 'RESTAURATION EN ÉCHEC', "\n";
            break;
        case 'smoke':
            // Parcourt des pages GET en tant qu'administrateur et signale toute erreur interne avec sa cause
            $admin = \App\Core\Database::one("SELECT id, agency_id FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1");
            $_SESSION['uid'] = (int)$admin['id'];
            $_SESSION['agency_id'] = (int)$admin['agency_id'];
            $router = require BASE_PATH . '/app/routes.php';
            $bad = 0;
            foreach (explode(',', (string)($_POST['paths'] ?? '/')) as $path) {
                $_GET = [];
                if ($q = parse_url($path, PHP_URL_QUERY)) {
                    parse_str($q, $_GET);
                }
                ob_start();
                try {
                    $router->dispatch('GET', parse_url($path, PHP_URL_PATH) ?: '/');
                    echo 'ok    ', $path, ' (', strlen((string)ob_get_clean()), " octets)\n";
                } catch (Throwable $e) {
                    ob_end_clean();
                    $bad++;
                    echo 'ERREUR ', $path, ' : ', get_class($e), ' : ', $e->getMessage(), ' (', basename($e->getFile()), ':', $e->getLine(), ")\n";
                }
            }
            echo $bad ? "$bad page(s) en erreur\n" : "Toutes les pages répondent\n";
            break;
        default:
            echo "Tâche inconnue.\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERREUR : ', get_class($e), ' : ', $e->getMessage(), "\n";
}
