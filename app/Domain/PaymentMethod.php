<?php
declare(strict_types=1);

namespace App\Domain;

enum PaymentMethod: string
{
    case Especes  = 'especes';
    case Orange   = 'orange';
    case Mtn      = 'mtn';
    case Carte    = 'carte';
    case Virement = 'virement';
    case Cheque   = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::Especes  => 'Espèces',
            self::Orange   => 'Orange Money',
            self::Mtn      => 'MTN MoMo',
            self::Carte    => 'Carte bancaire',
            self::Virement => 'Virement',
            self::Cheque   => 'Chèque',
        };
    }

    public function isMobileMoney(): bool
    {
        return in_array($this, [self::Orange, self::Mtn], true);
    }

    /** Les encaissements comptoir passent par une session de caisse ouverte. */
    public function needsCashSession(): bool
    {
        return !in_array($this, [self::Virement, self::Cheque], true);
    }

    /** @return list<self> */
    public static function counter(): array
    {
        return [self::Especes, self::Orange, self::Mtn, self::Carte];
    }
}
