<?php
declare(strict_types=1);

namespace App\Domain;

enum OrderStatus: string
{
    case EnAtelier = 'en_atelier';
    case Pret      = 'pret';
    case Retire    = 'retire';
    case Livre     = 'livre';
    case Annule    = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::EnAtelier => 'En atelier',
            self::Pret      => 'Prête',
            self::Retire    => 'Retirée',
            self::Livre     => 'Livrée',
            self::Annule    => 'Annulée',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pret => 'green',
            self::Annule => 'red',
            self::EnAtelier => 'orange',
            default => '',
        };
    }
}
