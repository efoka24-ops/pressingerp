<?php
declare(strict_types=1);

namespace App\Domain;

enum Role: string
{
    case Direction  = 'direction';
    case Manager    = 'manager';
    case Comptoir   = 'comptoir';
    case Atelier    = 'atelier';
    case Qualite    = 'qualite';
    case Commercial = 'commercial';

    public function label(): string
    {
        return match ($this) {
            self::Direction  => 'Direction',
            self::Manager    => 'Responsable d\'agence',
            self::Comptoir   => 'Agent de comptoir',
            self::Atelier    => 'Opérateur atelier',
            self::Qualite    => 'Contrôle qualité',
            self::Commercial => 'Commercial',
        };
    }

    /** @return list<string> */
    public function modules(): array
    {
        return match ($this) {
            self::Direction, self::Manager => array_keys(Module::ALL),
            self::Comptoir   => ['counter', 'clients', 'orders', 'trace', 'cash'],
            self::Atelier    => ['production', 'trace'],
            self::Qualite    => ['quality', 'production', 'trace'],
            self::Commercial => ['clients', 'commercial', 'marketing', 'bi'],
        };
    }

    public function can(string $module): bool
    {
        return in_array($module, $this->modules(), true);
    }

    public function home(): string
    {
        return match ($this) {
            self::Direction, self::Manager => '/cockpit',
            self::Comptoir   => '/comptoir',
            self::Atelier    => '/scan',
            self::Qualite    => '/qualite',
            self::Commercial => '/commercial',
        };
    }
}
