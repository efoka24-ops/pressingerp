<?php
declare(strict_types=1);

namespace App\Services\Messaging;

/** Registre des passerelles. Les tests peuvent les remplacer (`override`). */
final class Gateways
{
    /** @var ?array<string,Gateway> */
    private static ?array $override = null;

    /** @param ?array<string,Gateway> $map canal => passerelle ; null pour revenir aux vraies */
    public static function override(?array $map): void
    {
        self::$override = $map;
    }

    public static function for(string $channel): ?Gateway
    {
        if (self::$override !== null) {
            return self::$override[$channel] ?? null;
        }
        return match ($channel) {
            'email'    => new SmtpGateway(),
            'sms'      => new HttpSmsGateway(),
            'whatsapp' => new WhatsAppGateway(),
            default    => null,
        };
    }

    /** @return array<string,bool> canal => configuré ? */
    public static function status(): array
    {
        $out = [];
        foreach (['sms', 'whatsapp', 'email'] as $ch) {
            $out[$ch] = self::for($ch)?->configured() ?? false;
        }
        return $out;
    }
}
