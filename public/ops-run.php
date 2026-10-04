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
        default:
            echo "Tâche inconnue.\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERREUR : ', get_class($e), ' : ', $e->getMessage(), "\n";
}
