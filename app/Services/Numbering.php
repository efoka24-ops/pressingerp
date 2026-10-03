<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Numérotation séquentielle par année, sûre en concurrence (verrou de ligne). */
final class Numbering
{
    /** $format reçoit (année, valeur) : 'PR-%d-%06d', 'CL-%2$06d'… */
    public static function next(string $name, string $format): string
    {
        $year = (int)date('Y');
        return Database::transaction(static function () use ($name, $year, $format): string {
            Database::run('INSERT INTO counters (name, year, value) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE value = value', [$name, $year]);
            $value = (int)Database::value('SELECT value FROM counters WHERE name = ? AND year = ? FOR UPDATE', [$name, $year]) + 1;
            Database::run('UPDATE counters SET value = ? WHERE name = ? AND year = ?', [$value, $name, $year]);
            return sprintf($format, $year, $value);
        });
    }
}
