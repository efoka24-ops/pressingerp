<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/** Journal des impressions d'étiquettes : première impression libre, réimpression motivée, étiquetage manuel tracé. */
final class LabelService
{
    public static function needsReason(int $orderId): bool
    {
        return (int)Database::value("SELECT COUNT(*) FROM label_prints WHERE order_id = ? AND kind <> 'manual'", [$orderId]) > 0;
    }

    /** @return string 'print' | 'reprint' | 'manual' */
    public static function record(int $orderId, bool $manual, string $reason): string
    {
        $reason = trim($reason);
        if (!$manual && self::needsReason($orderId) && $reason === '') {
            throw new \DomainException('Motif obligatoire pour réimprimer les étiquettes.');
        }
        $kind = $manual ? 'manual' : (self::needsReason($orderId) ? 'reprint' : 'print');
        Database::insert('label_prints', ['order_id' => $orderId, 'kind' => $kind, 'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null, 'user_id' => Auth::id() ?: null, 'created_at' => now()]);
        if ($kind !== 'print') {
            Audit::log('labels.' . $kind, 'orders', $orderId, [], null, null, $reason !== '' ? $reason : 'Imprimante indisponible : étiquetage manuel');
        }
        return $kind;
    }
}
