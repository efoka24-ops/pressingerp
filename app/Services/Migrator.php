<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Migrations non destructives : rejoue seulement les fichiers de database/migrations/ absents de schema_migrations. */
final class Migrator
{
    /** @return list<string> une ligne par migration (+ appliquée, = déjà faite) */
    public static function run(): array
    {
        $pdo = Database::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB');
        $done = array_column(Database::all('SELECT name FROM schema_migrations'), 'name');

        $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                $out[] = "= $name (déjà appliquée)";
                continue;
            }
            $sql = (string)preg_replace('/^--.*$/m', '', (string)file_get_contents($file));
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                $pdo->exec($stmt);
            }
            Database::insert('schema_migrations', ['name' => $name, 'applied_at' => now()]);
            $out[] = "+ $name";
        }
        return $out;
    }

    /** Données de référence (agence, atelier, tarifs, objectifs) : une seule fois, base sans utilisateur. */
    public static function seedReference(): ?int
    {
        if ((int)Database::value('SELECT COUNT(*) FROM users') > 0) {
            return null;
        }
        $dla = Database::insert('agencies', ['code' => 'DLA', 'name' => 'Douala', 'phone' => null, 'is_workshop' => 0]);
        Database::insert('agencies', ['code' => 'AT', 'name' => 'Atelier central', 'phone' => null, 'is_workshop' => 1]);
        foreach ([
            ['Chemise', 1000, 'piece', 0], ['Pantalon', 1200, 'piece', 0], ['Costume 2 pièces', 4500, 'piece', 0],
            ['Veste', 2500, 'piece', 0], ['Robe', 2500, 'piece', 0], ['Robe de soirée', 6000, 'piece', 1],
            ['Boubou', 3500, 'piece', 0], ['Pagne', 1000, 'piece', 0], ['Drap', 1500, 'piece', 0],
            ['Couverture', 3500, 'piece', 0], ['Nappe', 1200, 'piece', 0], ['Tenue médicale', 800, 'piece', 0],
            ['Uniforme', 1500, 'piece', 0], ['Chaussures', 3000, 'piece', 1], ['Rideau', 1800, 'm2', 0], ['Tapis', 2500, 'm2', 0],
        ] as $i => [$n, $p, $u, $f]) {
            Database::insert('articles', ['name' => $n, 'price' => $p, 'unit' => $u, 'fragile' => $f, 'sort' => $i, 'active' => 1]);
        }
        foreach ([['ca', 14000000], ['ca_jour', 500000], ['recouvrement', 3000000], ['reprise_max', 3]] as [$metric, $target]) {
            Database::insert('objectives', ['month' => date('Y-m'), 'metric' => $metric, 'target' => $target]);
        }
        return $dla;
    }

    /** Crée un administrateur (rôle direction) avec un mot de passe aléatoire ; retourne [login, mot de passe]. */
    public static function createAdmin(int $agencyId, string $login = 'admin'): array
    {
        $password = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 14);
        Database::insert('users', [
            'agency_id' => $agencyId, 'name' => 'Administrateur', 'login' => $login,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'direction', 'active' => 1,
        ]);
        return [$login, $password];
    }
}
