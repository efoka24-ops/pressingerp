<?php
declare(strict_types=1);

/**
 * Crée un administrateur (rôle direction) avec un mot de passe aléatoire : php bin/create-admin.php [login]
 * Initialise aussi les données de référence si la base n'a encore aucun utilisateur.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\Migrator;

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
$login = $argv[1] ?? 'admin';
if (Database::value('SELECT COUNT(*) FROM users WHERE login = ?', [$login])) {
    exit("Le compte « $login » existe déjà.\n");
}
Migrator::run();
$agency = Migrator::seedReference() ?? (int)Database::value('SELECT id FROM agencies WHERE is_workshop = 0 ORDER BY id LIMIT 1');
[$l, $pw] = Migrator::createAdmin($agency, $login);
echo "Identifiant : $l\nMot de passe : $pw\n(affiché une seule fois)\n";
