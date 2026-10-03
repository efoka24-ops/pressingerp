<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                (string)Config::get('db.dsn'),
                (string)Config::get('db.user'),
                (string)Config::get('db.pass'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => true, // autorise la réutilisation d'un paramètre nommé
                ]
            );
            // Aligne le fuseau MySQL sur celui de PHP (NOW(), CURDATE())
            self::$pdo->exec("SET NAMES utf8mb4, time_zone = '" . date('P') . "'");
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')';
        self::run($sql, self::normalize($data));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = [];
        foreach ($data as $col => $v) {
            $set[] = "$col = :set_$col";
            $params["set_$col"] = $v;
        }
        return self::run("UPDATE $table SET " . implode(', ', $set) . " WHERE $where", self::normalize($params))->rowCount();
    }

    /** Transaction ré-entrante : un appel imbriqué s'exécute dans la transaction parente. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function normalize(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_bool($v)) {
                $data[$k] = (int)$v;
            }
        }
        return $data;
    }
}
