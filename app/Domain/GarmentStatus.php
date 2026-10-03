<?php
declare(strict_types=1);

namespace App\Domain;

enum GarmentStatus: string
{
    case ATraiter   = 'a_traiter';
    case EnCours    = 'en_cours';
    case Bloque     = 'bloque';
    case AReprendre = 'a_reprendre';
    case Termine    = 'termine';

    public function label(): string
    {
        return match ($this) {
            self::ATraiter   => 'À traiter',
            self::EnCours    => 'En cours',
            self::Bloque     => 'Bloqué',
            self::AReprendre => 'À reprendre',
            self::Termine    => 'Terminé',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::EnCours, self::Termine   => 'green',
            self::Bloque, self::AReprendre => 'red',
            self::ATraiter                 => '',
        };
    }
}
