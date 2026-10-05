<?php
declare(strict_types=1);

namespace App\Domain;

enum ServiceLevel: string
{
    case Standard = 'standard';
    case Express  = 'express';
    case Vip      = 'vip';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Express  => 'Express',
            self::Vip      => 'VIP',
        };
    }

    public function surchargePct(): int
    {
        return match ($this) {
            self::Standard => 0,
            self::Express  => (int)\App\Services\SettingsService::get('surcharge.express'),
            self::Vip      => (int)\App\Services\SettingsService::get('surcharge.vip'),
        };
    }

    public function delayHours(): int
    {
        return match ($this) {
            self::Standard => 72,
            self::Express  => 24,
            self::Vip      => 48,
        };
    }

    /** Date promise : délai, arrondi à l'heure, ramené dans les heures d'ouverture (8 h–19 h, fermé le dimanche). */
    public function promisedAt(?int $from = null): string
    {
        $t = ($from ?? time()) + $this->delayHours() * 3600;
        $t = (int)(ceil($t / 3600) * 3600);
        $h = (int)date('G', $t);
        if ($h < 8) {
            $t = strtotime(date('Y-m-d 10:00:00', $t));
        } elseif ($h >= 19) {
            $t = strtotime(date('Y-m-d 10:00:00', $t + 86400));
        }
        if ((int)date('N', $t) === 7) {
            $t += 86400;
        }
        return date('Y-m-d H:i:s', $t);
    }
}
