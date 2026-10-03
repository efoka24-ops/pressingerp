<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * File d'attente des messages sortants (SMS / WhatsApp / e-mail).
 * L'envoi réel est fait par bin/send-messages.php via une passerelle (voir MessageGateway).
 */
final class Notifier
{
    public static function queue(int $clientId, string $body, ?string $channel = null, ?int $campaignId = null): void
    {
        $channel ??= (string)(Database::value('SELECT preferred_channel FROM clients WHERE id = ?', [$clientId]) ?? 'sms');
        Database::insert('messages', [
            'client_id'   => $clientId,
            'channel'     => $channel,
            'body'        => $body,
            'campaign_id' => $campaignId,
            'status'      => 'en_attente',
            'created_at'  => now(),
        ]);
    }
}
