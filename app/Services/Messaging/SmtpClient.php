<?php
declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * Client SMTP minimal (connexion directe, STARTTLS ou SSL, AUTH LOGIN/PLAIN). Sans dépendance : l'hébergement n'a pas Composer.
 * Utilisation : open() → auth() → send() (autant de fois que voulu) → quit().
 */
final class SmtpClient
{
    /** @var resource|null */
    private $fp = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly string $secure = 'tls',   // 'tls' (STARTTLS), 'ssl' (connexion chiffrée d'emblée) ou '' (aucun)
        private readonly int $timeout = 12,
        private readonly bool $verifyPeer = true,
    ) {
    }

    public function open(): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $this->verifyPeer, 'verify_peer_name' => $this->verifyPeer, 'SNI_enabled' => true, 'peer_name' => $this->host]]);
        $fp = @stream_socket_client(($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new \RuntimeException("Connexion SMTP impossible ({$this->host}:{$this->port}) : " . trim("$errno $errstr"));
        }
        $this->fp = $fp;
        stream_set_timeout($fp, $this->timeout);
        $this->expect($this->read(), [220], 'bannière');
        $ehlo = $this->command('EHLO ' . $this->helloName(), [250]);
        if ($this->secure === 'tls') {
            if (stripos($ehlo, 'STARTTLS') === false) {
                throw new \RuntimeException('Le serveur SMTP ne propose pas STARTTLS.');
            }
            $this->command('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('Échec de la négociation TLS avec le serveur SMTP.');
            }
            $this->command('EHLO ' . $this->helloName(), [250]);
        }
    }

    public function auth(string $user, string $pass): void
    {
        $r = $this->command('AUTH LOGIN', [334, 235, 503], 'authentification');
        if (str_starts_with($r, '334')) {
            $this->command(base64_encode($user), [334], 'identifiant');
            $this->command(base64_encode($pass), [235], 'authentification');
        }
    }

    /** @return string identifiant du message */
    public function send(string $from, string $fromName, string $to, string $subject, string $body): string
    {
        $clean = fn(string $v) => trim((string)preg_replace('/[\r\n]+/', ' ', $v));   // aucune injection d'en-tête
        $from = $clean($from);
        $to = $clean($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Adresse e-mail invalide.');
        }
        $id = bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@'), 1);
        $encode = fn(string $v) => '=?UTF-8?B?' . base64_encode($v) . '?=';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($fromName !== '' ? $encode($clean($fromName)) . ' ' : '') . "<$from>",
            "To: <$to>",
            'Subject: ' . $encode($clean($subject)),
            "Message-ID: <$id>",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        $this->command("MAIL FROM:<$from>", [250]);
        $this->command("RCPT TO:<$to>", [250, 251]);
        $this->command('DATA', [354]);
        fwrite($this->fp, implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n") . "\r\n.\r\n");
        $this->expect($this->read(), [250], 'envoi du message');
        return $id;
    }

    public function quit(): void
    {
        if ($this->fp) {
            @fwrite($this->fp, "QUIT\r\n");
            @fclose($this->fp);
            $this->fp = null;
        }
    }

    public function __destruct()
    {
        $this->quit();
    }

    private function helloName(): string
    {
        return preg_replace('/[^A-Za-z0-9.\-]/', '', (string)(parse_url((string)\App\Core\Config::get('app.url'), PHP_URL_HOST) ?: 'localhost')) ?: 'localhost';
    }

    /** @param list<int> $ok codes acceptés */
    private function command(string $line, array $ok, ?string $label = null): string
    {
        fwrite($this->fp, $line . "\r\n");
        $reply = $this->read();
        // Le libellé de l'erreur ne reprend jamais la ligne envoyée : elle peut contenir des identifiants
        $this->expect($reply, $ok, $label ?? (string)strtok($line, ' :'));
        return $reply;
    }

    private function read(): string
    {
        $out = '';
        while (($l = fgets($this->fp, 1024)) !== false) {
            $out .= $l;
            if (isset($l[3]) && $l[3] === ' ') {
                break;
            }
        }
        if ($out === '') {
            throw new \RuntimeException('Le serveur SMTP ne répond pas (délai dépassé).');
        }
        return trim($out);
    }

    private function expect(string $reply, array $codes, string $what): void
    {
        if (!in_array((int)substr($reply, 0, 3), $codes, true)) {
            throw new \RuntimeException("SMTP « $what » refusé : " . mb_substr($reply, 0, 160));
        }
    }
}
