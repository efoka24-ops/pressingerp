<?php
declare(strict_types=1);

/**
 * Migrations à usage unique (hébergement sans SSH).
 * POST /migrate.php?token=...  protégé par MIGRATE_TOKEN (config.local.php).
 * Non destructif. Au premier passage, crée les données de référence et un administrateur
 * (mot de passe aléatoire affiché une seule fois).
 * À SUPPRIMER du serveur juste après usage, puis vider MIGRATE_TOKEN (ops/deploy.sh migrate le fait).
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Services\Migrator;

header('Content-Type: text/plain; charset=utf-8');

$expected = (string)Config::get('migrate_token', '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $expected === '' || !hash_equals($expected, (string)($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit("Not found\n");
}

echo implode("\n", Migrator::run()), "\n";
if (($agency = Migrator::seedReference()) !== null) {
    [$login, $password] = Migrator::createAdmin($agency);
    echo "→ Données de référence créées\n→ Administrateur\n   identifiant : $login\n   mot de passe : $password\n   (affiché une seule fois : changez-le après connexion)\n";
}
echo "Terminé.\n";
