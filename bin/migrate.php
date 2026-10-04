<?php
declare(strict_types=1);

/**
 * Migrations non destructives : php bin/migrate.php
 * Sur un hébergement sans SSH, utiliser public/migrate.php (jeton) à la place.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\Migrator;

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
echo implode("\n", Migrator::run()), "\nTerminé.\n";
