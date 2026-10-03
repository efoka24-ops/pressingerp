<?php
declare(strict_types=1);

namespace App\Domain;

use App\Core\Database;

final class Module
{
    /** code => [numéro, libellé, url] */
    public const ALL = [
        'cockpit'    => ['00', 'Cockpit direction', '/cockpit'],
        'counter'    => ['00', 'Accueil comptoir', '/comptoir'],
        'clients'    => ['01', 'Clients / CRM', '/clients'],
        'orders'     => ['02', 'Commandes', '/commandes'],
        'trace'      => ['03', 'Traçabilité', '/tracabilite'],
        'production' => ['04', 'Production', '/production'],
        'quality'    => ['05', 'Qualité', '/qualite'],
        'cash'       => ['06', 'Caisse & Finance', '/caisse'],
        'commercial' => ['07', 'Commercial & Recouvrement', '/commercial'],
        'marketing'  => ['08', 'Marketing & Fidélité', '/marketing'],
        'stock'      => ['09', 'Stocks', '/stocks'],
        'bi'         => ['10', 'Business Intelligence', '/bi'],
    ];

    private static array $badges = [];

    /** Pastille de compteur dans le menu */
    public static function badge(string $code): int
    {
        return self::$badges[$code] ??= match ($code) {
            'production' => (int)Database::value("SELECT COUNT(*) FROM garments WHERE status = 'bloque'"),
            'stock'      => (int)Database::value('SELECT COUNT(*) FROM stock_items WHERE quantity < min_qty'),
            'quality'    => (int)Database::value("SELECT COUNT(*) FROM complaints WHERE status <> 'cloturee'"),
            default      => 0,
        };
    }
}
