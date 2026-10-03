<?php
declare(strict_types=1);

namespace App\Domain;

/** Étapes du parcours d'une pièce, dans l'ordre. */
enum Step: string
{
    case Reception = 'reception';
    case Tri       = 'tri';
    case Detachage = 'detachage';
    case Lavage    = 'lavage';
    case Sechage   = 'sechage';
    case Repassage = 'repassage';
    case Finition  = 'finition';
    case Controle  = 'controle';
    case Emballage = 'emballage';
    case Pret      = 'pret';
    case Retire    = 'retire';

    public function label(): string
    {
        return match ($this) {
            self::Reception => 'Réception',
            self::Tri       => 'Tri / diagnostic',
            self::Detachage => 'Détachage',
            self::Lavage    => 'Lavage / sec',
            self::Sechage   => 'Séchage',
            self::Repassage => 'Repassage',
            self::Finition  => 'Finition',
            self::Controle  => 'Contrôle qualité',
            self::Emballage => 'Emballage',
            self::Pret      => 'Prêt à retirer',
            self::Retire    => 'Retiré',
        };
    }

    /** Libellé simplifié pour le client (page de suivi) */
    public function publicLabel(): string
    {
        return match ($this) {
            self::Reception => 'Reçue',
            self::Controle  => 'Contrôle',
            self::Emballage, self::Pret => 'Prête',
            self::Retire    => 'Retirée',
            default         => 'En traitement',
        };
    }

    public function index(): int
    {
        return (int)array_search($this, self::cases(), true);
    }

    public function next(): ?self
    {
        return self::cases()[$this->index() + 1] ?? null;
    }

    /** Étapes visibles sur la carte / le tableau de production */
    public static function production(): array
    {
        return [self::Tri, self::Detachage, self::Lavage, self::Sechage, self::Repassage, self::Finition, self::Controle, self::Emballage, self::Pret];
    }

    /** Étapes vers lesquelles le contrôle qualité peut renvoyer */
    public static function reworkTargets(): array
    {
        return [self::Detachage, self::Lavage, self::Sechage, self::Repassage, self::Finition];
    }

    /** Fragment SQL FIELD(...) pour trier par avancement */
    public static function sqlOrder(string $column): string
    {
        return 'FIELD(' . $column . ", '" . implode("','", array_map(fn(self $s) => $s->value, self::cases())) . "')";
    }
}
