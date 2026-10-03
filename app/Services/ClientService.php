<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class ClientService
{
    /** Encours d'un client pro : factures non soldées + commandes en compte non encore facturées. */
    public static function outstanding(int $clientId): int
    {
        return (int)Database::value(
            "SELECT (SELECT COALESCE(SUM(total - paid), 0) FROM invoices WHERE client_id = :c AND status <> 'payee')
                  + (SELECT COALESCE(SUM(total - paid), 0) FROM orders WHERE client_id = :c AND on_account = 1 AND invoice_id IS NULL AND status <> 'annule')",
            ['c' => $clientId]
        );
    }

    public static function normalizePhone(string $phone): string
    {
        $p = preg_replace('/[^\d+]/', '', $phone) ?? '';
        if (str_starts_with($p, '00')) {
            $p = '+' . substr($p, 2);
        }
        if (!str_starts_with($p, '+') && strlen($p) === 10) {
            $p = '+225' . $p; // Côte d'Ivoire par défaut
        }
        return $p;
    }

    public static function formatPhone(string $p): string
    {
        if (preg_match('/^\+225(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $p, $m)) {
            return "+225 $m[1] $m[2] $m[3] $m[4] $m[5]";
        }
        return $p;
    }
}
