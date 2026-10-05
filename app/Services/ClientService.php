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
        if (!str_starts_with($p, '+') && strlen($p) === 9 && in_array($p[0], ['6', '2'], true)) {
            $p = '+237' . $p; // Cameroun par défaut
        }
        return $p;
    }

    /**
     * Retrouve le client par son numéro, ou le crée s'il est identifiable. Utilisé par la synchronisation hors-ligne.
     * @return array{client:array,created:bool}
     */
    public static function findOrCreate(string $name, string $phone, string $type = 'particulier'): array
    {
        $type = $type === 'pro' ? 'pro' : 'particulier';
        $phone = self::normalizePhone($phone);
        if ($err = self::nameError($name, $type) ?? self::phoneError($phone)) {
            throw new \DomainException('Client non identifiable — ' . $err);
        }
        $existing = Database::one('SELECT * FROM clients WHERE phone = ?', [$phone]);
        if ($existing) {
            return ['client' => $existing, 'created' => false];
        }
        $id = Database::insert('clients', [
            'type' => $type, 'name' => mb_substr(trim($name), 0, 150), 'phone' => $phone,
            'code' => Numbering::next('client', 'CL-%2$06d'), 'created_at' => now(),
        ]);
        Audit::log('client.create', 'clients', $id, ['source' => 'hors-ligne']);
        return ['client' => Database::one('SELECT * FROM clients WHERE id = ?', [$id]), 'created' => true];
    }

    /** Message d'erreur si le numéro (déjà normalisé) ne permet pas d'identifier et de joindre le client. */
    public static function phoneError(string $normalized): ?string
    {
        if (preg_match('/^\+237[62]\d{8}$/', $normalized)) {
            return null; // mobile (6…) ou fixe (2…) camerounais, 9 chiffres
        }
        if (preg_match('/^\+(?!237)[1-9]\d{7,14}$/', $normalized)) {
            return null; // numéro étranger saisi avec son indicatif
        }
        return 'Numéro invalide : 9 chiffres commençant par 6 (mobile) ou 2 (fixe), par exemple 6 70 12 34 56. Un numéro étranger s\'écrit avec son indicatif (+33…).';
    }

    /** Un particulier doit avoir un prénom et un nom ; une entreprise, une raison sociale. */
    public static function nameError(string $name, string $type): ?string
    {
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));
        if ($type === 'pro') {
            return mb_strlen($name) >= 3 ? null : 'Raison sociale trop courte.';
        }
        $parts = explode(' ', $name);
        if (count($parts) < 2 || min(array_map('mb_strlen', $parts)) < 2) {
            return 'Nom et prénom obligatoires : le client doit pouvoir être identifié.';
        }
        return null;
    }

    /** Un client sans nom complet ou sans numéro valide ne peut pas déposer de vêtements. */
    public static function identifiableError(array $client): ?string
    {
        return self::nameError((string)($client['name'] ?? ''), (string)($client['type'] ?? 'particulier'))
            ?? self::phoneError((string)($client['phone'] ?? ''));
    }

    public static function formatPhone(string $p): string
    {
        if (preg_match('/^\+237(\d)(\d{2})(\d{2})(\d{2})(\d{2})$/', $p, $m)) {
            return "+237 $m[1] $m[2] $m[3] $m[4] $m[5]";
        }
        return $p;
    }
}
