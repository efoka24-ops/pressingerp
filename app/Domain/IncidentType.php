<?php
declare(strict_types=1);

namespace App\Domain;

/** Types d'incident en production (cahier des charges §9.2). */
enum IncidentType: string
{
    case Endommage      = 'endommage';
    case Couleur        = 'couleur';
    case Tache          = 'tache';
    case Incomplet      = 'incomplet';
    case Identification = 'identification';
    case Machine        = 'machine';
    case Produit        = 'produit';
    case Retraitement   = 'retraitement';
    case Autre          = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Endommage      => 'Vêtement endommagé',
            self::Couleur        => 'Couleur instable',
            self::Tache          => 'Tache difficile',
            self::Incomplet      => 'Article incomplet',
            self::Identification => 'Erreur d\'identification',
            self::Machine        => 'Problème machine',
            self::Produit        => 'Problème produit',
            self::Retraitement   => 'Demande de retraitement',
            self::Autre          => 'Autre incident',
        };
    }

    /** Un incident critique remonte au manager et se journalise en priorité (RG7). */
    public function critical(): bool
    {
        return in_array($this, [self::Endommage, self::Identification], true);
    }
}
