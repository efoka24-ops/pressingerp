<?php
declare(strict_types=1);

namespace App\Services\Messaging;

use App\Core\Config;

/**
 * E-mail par SMTP. Configuration (config.local.php) :
 *   'mail' => ['host' => '…', 'port' => 587, 'secure' => 'tls', 'user' => '…', 'pass' => '…', 'from' => 'noreply@…', 'from_name' => 'Pressing', 'verify_tls' => true]
 */
final class SmtpGateway implements Gateway
{
    public function channel(): string
    {
        return 'email';
    }

    public function configured(): bool
    {
        $c = (array)Config::get('mail', []);
        return !empty($c['host']) && !empty($c['user']) && !empty($c['pass']) && !empty($c['from']);
    }

    public function send(string $to, string $subject, string $body): ?string
    {
        $c = (array)Config::get('mail', []);
        $smtp = self::client($c);
        try {
            $smtp->open();
            $smtp->auth((string)$c['user'], (string)$c['pass']);
            return $smtp->send((string)$c['from'], (string)($c['from_name'] ?? ''), $to, $subject, $body);
        } finally {
            $smtp->quit();
        }
    }

    /** Teste la connexion et les identifiants sans envoyer aucun message. */
    public static function verify(): string
    {
        $c = (array)Config::get('mail', []);
        if (empty($c['host'])) {
            throw new \RuntimeException('Serveur SMTP non configuré.');
        }
        $smtp = self::client($c);
        try {
            $smtp->open();
            $smtp->auth((string)$c['user'], (string)$c['pass']);
            return "Connexion et authentification réussies ({$c['host']}:" . (int)($c['port'] ?? 587) . ', ' . strtoupper((string)($c['secure'] ?? 'tls')) . ').';
        } finally {
            $smtp->quit();
        }
    }

    private static function client(array $c): SmtpClient
    {
        return new SmtpClient((string)$c['host'], (int)($c['port'] ?? 587), (string)($c['secure'] ?? 'tls'), 12, (bool)($c['verify_tls'] ?? true));
    }
}
