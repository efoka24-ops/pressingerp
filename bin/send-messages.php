<?php
declare(strict_types=1);

/**
 * Envoi des messages en file (SMS / WhatsApp / e-mail).
 * À lancer en tâche planifiée, par ex. toutes les minutes :
 *   * * * * * php /chemin/pressing-erp/bin/send-messages.php
 *
 * Remplacez LogGateway par l'adaptateur de votre fournisseur
 * (Orange SMS API, Twilio, WhatsApp Business Cloud API, SMTP…).
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Database;

interface MessageGateway
{
    /** @throws RuntimeException en cas d'échec */
    public function send(string $channel, string $to, string $body, ?string $email): void;
}

final class LogGateway implements MessageGateway
{
    public function send(string $channel, string $to, string $body, ?string $email): void
    {
        $line = sprintf("[%s] %s → %s : %s\n", date('c'), strtoupper($channel), $channel === 'email' ? ($email ?? '?') : $to, $body);
        file_put_contents(BASE_PATH . '/storage/messages.log', $line, FILE_APPEND | LOCK_EX);
    }
}

@mkdir(BASE_PATH . '/storage', 0775, true);
$gateway = new LogGateway();
$batch = Database::all(
    "SELECT m.*, c.phone, c.email FROM messages m JOIN clients c ON c.id = m.client_id
     WHERE m.status = 'en_attente' ORDER BY m.id LIMIT 200"
);
$sent = 0;
foreach ($batch as $m) {
    try {
        $channel = $m['channel'] === 'email' && !$m['email'] ? 'sms' : $m['channel'];
        $gateway->send($channel, $m['phone'], $m['body'], $m['email']);
        Database::update('messages', ['status' => 'envoye', 'sent_at' => now(), 'error' => null], 'id = :id', ['id' => $m['id']]);
        $sent++;
    } catch (Throwable $e) {
        Database::update('messages', ['status' => 'echec', 'error' => mb_substr($e->getMessage(), 0, 255)], 'id = :id', ['id' => $m['id']]);
    }
}
echo "$sent / " . count($batch) . " message(s) envoyé(s).\n";
