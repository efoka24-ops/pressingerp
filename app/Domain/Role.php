<?php
declare(strict_types=1);

namespace App\Domain;

enum Role: string
{
    case Admin       = 'admin';
    case Direction   = 'direction';
    case Manager     = 'manager';
    case Comptoir    = 'comptoir';
    case Atelier     = 'atelier';
    case Superviseur = 'superviseur';
    case Qualite     = 'qualite';
    case Livreur     = 'livreur';
    case Commercial  = 'commercial';
    case Marketing   = 'marketing';

    /** Actions : R lecture · C création · U modification · V validation · D suppression */
    public const ACTIONS = ['read' => 'R', 'create' => 'C', 'update' => 'U', 'validate' => 'V', 'delete' => 'D'];

    public function label(): string
    {
        return match ($this) {
            self::Admin       => 'Administrateur système',
            self::Direction   => 'Direction',
            self::Manager     => 'Responsable d\'agence',
            self::Comptoir    => 'Agent de comptoir',
            self::Atelier     => 'Opérateur atelier',
            self::Superviseur => 'Superviseur production',
            self::Qualite     => 'Contrôle qualité',
            self::Livreur     => 'Livreur',
            self::Commercial  => 'Commercial / recouvrement',
            self::Marketing   => 'Responsable marketing',
        };
    }

    /**
     * Droits par module (lettres de self::ACTIONS). « Limité » = sous-ensemble de lettres.
     * @return array<string,string>
     */
    public function rights(): array
    {
        $business = array_fill_keys(['cockpit', 'counter', 'clients', 'orders', 'trace', 'production', 'quality', 'cash', 'commercial', 'marketing', 'stock', 'bi', 'pricing', 'delivery'], 'RCUVD');
        return match ($this) {
            self::Admin       => $business + ['admin' => 'RCUVD'],
            self::Direction   => array_map(fn() => 'RCUV', $business) + ['admin' => 'R'],
            // Responsable d'agence : limité à son agence, donc sans les modules dont les données ne sont pas filtrables par agence
            self::Manager     => array_merge(array_map(fn() => 'RCUV', array_diff_key($business, array_flip(['bi', 'commercial', 'marketing']))), ['pricing' => 'R']),
            self::Comptoir    => ['counter' => 'R', 'clients' => 'RCU', 'orders' => 'RCU', 'trace' => 'R', 'cash' => 'RCU', 'pricing' => 'R', 'delivery' => 'RCU'],
            self::Atelier     => ['production' => 'RU', 'trace' => 'R'],
            self::Superviseur => ['production' => 'RCUV', 'trace' => 'R', 'quality' => 'R', 'orders' => 'R'],
            self::Qualite     => ['quality' => 'RCUV', 'production' => 'R', 'trace' => 'R'],
            self::Livreur     => ['orders' => 'R', 'trace' => 'R', 'delivery' => 'RU'],
            self::Commercial  => ['clients' => 'RCU', 'commercial' => 'RCUV', 'marketing' => 'R', 'bi' => 'R'],
            self::Marketing   => ['clients' => 'R', 'marketing' => 'RCUV', 'bi' => 'R'],
        };
    }

    /** @return list<string> modules accessibles en lecture */
    public function modules(): array
    {
        return array_keys(array_filter($this->rights(), fn($r) => str_contains($r, 'R')));
    }

    public function can(string $module, string $action = 'read'): bool
    {
        $letter = self::ACTIONS[$action] ?? null;
        return $letter !== null && str_contains($this->rights()[$module] ?? '', $letter);
    }

    /** Rôles limités aux données de leur agence ; les autres voient le groupe (l'atelier central traite toutes les agences). */
    public function agencyScoped(): bool
    {
        return in_array($this, [self::Comptoir, self::Manager], true);
    }

    /** Rôles autorisés à choisir l'agence de travail à la connexion. */
    public function canSwitchAgency(): bool
    {
        return in_array($this, [self::Admin, self::Direction], true);
    }

    public function home(): string
    {
        return match ($this) {
            self::Admin, self::Direction, self::Manager => '/cockpit',
            self::Comptoir    => '/comptoir',
            self::Atelier, self::Superviseur => '/scan',
            self::Qualite     => '/qualite',
            self::Livreur     => '/livraisons',
            self::Commercial  => '/commercial',
            self::Marketing   => '/marketing',
        };
    }
}
