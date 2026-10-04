<?php
declare(strict_types=1);

/**
 * Recette automatisée : php tests/run.php
 * Les tests s'exécutent dans une transaction annulée : aucune donnée n'est conservée.
 * Sur l'hébergement sans SSH : ops/deploy.sh run selftest (passe par public/ops-run.php).
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
    require BASE_PATH . '/app/bootstrap.php';
}
require __DIR__ . '/lib.php';
foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $file) {
    require $file;
}

$r = run_tests();
echo implode("\n", $r['lines']), "\n";
echo "\n{$r['passed']} réussi(s), {$r['failed']} échec(s), {$r['skipped']} ignoré(s)\n";
if (PHP_SAPI === 'cli') {
    exit($r['failed'] > 0 ? 1 : 0);
}
