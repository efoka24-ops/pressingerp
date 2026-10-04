<?php
declare(strict_types=1);

/**
 * Sauvegarde quotidienne : php bin/backup.php
 * Cron conseillé (panneau Camoo) : 30 2 * * *  php /home/trugro9159/pressing-erp/pressing/bin/backup.php
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\Backup;

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
try {
    $r = Backup::run();
    echo implode("\n", $r['log']), "\n", $r['verified'] ? 'OK' : 'ATTENTION : sauvegarde non vérifiée', ' — ', $r['file'], "\n";
    exit($r['verified'] ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'ÉCHEC : ' . $e->getMessage() . "\n");
    exit(1);
}
