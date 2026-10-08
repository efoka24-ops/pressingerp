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
        $dsn = (string)\App\Core\Config::get('db.dsn');
        $engine = str_starts_with($dsn, 'sqlite:') ? '' : ' ENGINE=InnoDB';
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL)' . $engine);
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
            $sql = (string)file_get_contents($file);
            // Adapter MySQL → SQLite si nécessaire
            if (str_starts_with($dsn, 'sqlite:')) {
                $sql = self::adaptSqlForSqlite($sql);
            }
            foreach (self::statements($sql) as $stmt) {
                $pdo->exec($stmt);
            }
            Database::insert('schema_migrations', ['name' => $name, 'applied_at' => now()]);
            $out[] = "+ $name";
        }
        return $out;
    }

    /**
     * Triggers « ajout seul » (database/optional/) : appliqués si l'hébergeur le permet.
     * @return string « appliqués », ou la raison pour laquelle ils sont indisponibles
     */
    public static function applyOptionalTriggers(): string
    {
        $file = BASE_PATH . '/database/optional/append_only_triggers.sql';
        if (!is_file($file)) {
            return 'fichier absent';
        }
        try {
            foreach (self::statements((string)file_get_contents($file)) as $stmt) {
                Database::pdo()->exec($stmt);
            }
            return 'appliqués';
        } catch (\PDOException $e) {
            return 'indisponibles (' . mb_substr($e->getMessage(), 0, 160) . ')';
        }
    }

    public static function triggersInstalled(): bool
    {
        $dsn = (string)\App\Core\Config::get('db.dsn');
        if (str_starts_with($dsn, 'sqlite:')) {
            // SQLite : pas de información_schema, vérifie via sqlite_master
            return (int)Database::value('SELECT COUNT(*) FROM sqlite_master WHERE type = ? AND name LIKE ?', ['trigger', '%_no_%']) > 0;
        }
        // MySQL/MariaDB
        return (int)Database::value('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE ?', ['%_no_%']) > 0;
    }

    /** Adapter le SQL MySQL pour SQLite : enlever les clauses MySQL-spécifiques */
    private static function adaptSqlForSqlite(string $sql): string
    {
        // Remplacer ENGINE=InnoDB et charset/collate
        $sql = (string)preg_replace('/\s*ENGINE\s*=\s*\w+/i', '', $sql);
        $sql = (string)preg_replace('/\s*DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql);
        $sql = (string)preg_replace('/\s*COLLATE\s*=\s*[\w_]+/i', '', $sql);
        
        // Remplacer INT AUTO_INCREMENT PRIMARY KEY par INTEGER PRIMARY KEY AUTOINCREMENT
        $sql = (string)preg_replace('/\bINT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        // Remplacer BIGINT AUTO_INCREMENT PRIMARY KEY par INTEGER PRIMARY KEY AUTOINCREMENT
        $sql = (string)preg_replace('/\bBIGINT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        
        // Remplacer TINYINT(1) par INTEGER
        $sql = (string)preg_replace('/\bTINYINT\s*\(\s*1\s*\)/i', 'INTEGER', $sql);
        
        // Remplacer BIGINT par INTEGER
        $sql = (string)preg_replace('/\bBIGINT\b/i', 'INTEGER', $sql);
        
        // Remplacer INT par INTEGER (après les remplacements ci-dessus)
        $sql = (string)preg_replace('/\bINT\b(?!\s*[A-Za-z])/i', 'INTEGER', $sql);
        
        // Supprimer les INDEX KEY (pas les CONSTRAINT FK) : matcher les lignes KEY idx_... et UNIQUE KEY
        // Cette regex enlève: ,\n  KEY idx_... ou ,\n  UNIQUE KEY
        $sql = (string)preg_replace('/,\s*(?:UNIQUE\s+)?KEY\s+`?\w+`?\s*\([^)]+\)/im', '', $sql);
        
        // Enlever les clauses AFTER et BEFORE des ALTER TABLE (incompatibles SQLite)
        $sql = (string)preg_replace('/\s+(AFTER|BEFORE)\s+`?\w+`?,?/im', ',', $sql);
        
        // Nettoyer les virgules en doublon à la fin des parenthèses et les espaces blancs excessifs
        $sql = (string)preg_replace('/,\s*\)/m', ')', $sql);
        $sql = (string)preg_replace('/,\s*;/m', ';', $sql);
        $sql = (string)preg_replace('/\n\s*\n/m', "\n", $sql);
        
        return $sql;
    }

    /**
     * Découpe un fichier SQL. Par défaut le séparateur est « ; ».
     * Une ligne « -- @delimiter ;; » en tête change le séparateur (corps de trigger avec BEGIN … END).
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $delimiter = ';';
        if (preg_match('/^-- @delimiter (\S+)/m', $sql, $m)) {
            $delimiter = $m[1];
        }
        $sql = (string)preg_replace('/^--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', explode($delimiter, $sql)), fn($s) => $s !== ''));
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

    /** Crée un administrateur système avec un mot de passe aléatoire ; retourne [login, mot de passe]. */
    public static function createAdmin(int $agencyId, string $login = 'admin'): array
    {
        $password = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 14);
        Database::insert('users', [
            'agency_id' => $agencyId, 'name' => 'Administrateur', 'login' => $login,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'admin', 'active' => 1,
        ]);
        return [$login, $password];
    }
}
