<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

final class LoyaltyService
{
    public static function award(int $clientId, int $amount): int
    {
        $per = max(1, (int)Config::get('loyalty.fcfa_per_point', 100));
        $points = intdiv(max(0, $amount), $per);
        if ($points > 0) {
            Database::run('UPDATE clients SET loyalty_points = loyalty_points + ? WHERE id = ?', [$points, $clientId]);
        }
        return $points;
    }
}
