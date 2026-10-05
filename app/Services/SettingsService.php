<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/** Paramètres administrables, versionnés en ajout seul. La valeur courante est la ligne la plus récente. */
final class SettingsService
{
    /** clé => [libellé, type (int|bool|text), valeur par défaut, groupe] */
    public const DEFINITIONS = [
        'company.name'            => ['Raison sociale', 'text', 'Pressing', 'Entreprise'],
        'company.niu'             => ['NIU (numéro d\'identifiant unique)', 'text', '', 'Entreprise'],
        'tax.vat_rate'            => ['Taux de TVA (%)', 'text', '19.25', 'Entreprise'],
        'surcharge.express'       => ['Majoration niveau Express (%)', 'int', 50, 'Tarification'],
        'surcharge.vip'           => ['Majoration niveau VIP (%)', 'int', 20, 'Tarification'],
        'offline.block_size'      => ['Numéros réservés par plage (réception hors-ligne)', 'int', 40, 'Hors-ligne'],
        'cash.tolerance'          => ['Tolérance d\'écart de caisse (FCFA)', 'int', 1000, 'Caisse'],
        'photo.value_threshold'   => ['Photo obligatoire au-dessus de (FCFA par pièce)', 'int', 50000, 'Réception'],
        'reminder.days'           => ['Relances non retirés (jours, séparés par des virgules)', 'text', '2,7,15', 'Relances'],
        'reminder.manager_after'  => ['Alerte manager au-delà de (jours de non-retrait)', 'int', 15, 'Relances'],
        'vip.annual_threshold'    => ['Seuil VIP : CA annuel (FCFA)', 'int', 1500000, 'CRM'],
        'credit.block_over_limit' => ['Bloquer les commandes en compte au-delà du plafond', 'bool', 1, 'Recouvrement'],
        'compensation.max_factor' => ['Indemnisation maximale (× prix du service)', 'int', 10, 'Qualité'],
    ];

    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        return $default ?? (self::DEFINITIONS[$key][2] ?? null);
    }

    /** @return array<string,mixed> valeurs courantes, typées */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            $rows = Database::all(
                'SELECT s.key_name, s.value FROM settings s
                 JOIN (SELECT key_name, MAX(id) id FROM settings WHERE agency_id IS NULL GROUP BY key_name) m ON m.id = s.id'
            );
            foreach ($rows as $r) {
                self::$cache[$r['key_name']] = self::cast($r['key_name'], $r['value']);
            }
        }
        return self::$cache;
    }

    public static function set(string $key, string $value, string $reason): void
    {
        if (!isset(self::DEFINITIONS[$key])) {
            throw new \DomainException('Paramètre inconnu.');
        }
        if (trim($reason) === '') {
            throw new \DomainException('Motif obligatoire pour modifier un paramètre.');
        }
        $type = self::DEFINITIONS[$key][1];
        $value = trim($value);
        if ($type === 'int' && !preg_match('/^\d+$/', $value)) {
            throw new \DomainException('Valeur numérique entière attendue.');
        }
        if ($type === 'bool') {
            $value = in_array($value, ['1', 'on', 'oui', 'true'], true) ? '1' : '0';
        }
        if ($key === 'reminder.days' && !preg_match('/^\d+(,\d+)*$/', $value)) {
            throw new \DomainException('Format attendu : 2,7,15.');
        }
        $old = self::get($key);
        Database::transaction(function () use ($key, $value, $reason, $old): void {
            Database::insert('settings', ['key_name' => $key, 'agency_id' => null, 'value' => $value, 'reason' => mb_substr($reason, 0, 255), 'created_by' => Auth::id() ?: null, 'created_at' => now()]);
            Audit::log('settings.change', 'settings', null, ['key' => $key], $old, self::cast($key, $value), $reason);
        });
        self::$cache = null;
    }

    /** @return list<array> historique d'un paramètre, du plus récent au plus ancien */
    public static function history(string $key): array
    {
        return Database::all('SELECT s.*, u.name AS user_name FROM settings s LEFT JOIN users u ON u.id = s.created_by WHERE s.key_name = ? ORDER BY s.id DESC LIMIT 20', [$key]);
    }

    private static function cast(string $key, string $raw): mixed
    {
        return match (self::DEFINITIONS[$key][1] ?? 'text') {
            'int'  => (int)$raw,
            'bool' => $raw === '1',
            default => $raw,
        };
    }
}
