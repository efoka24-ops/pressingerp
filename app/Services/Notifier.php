<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Point d'entrée historique des messages sortants. Délègue à MessageService :
 * un message de campagne est marketing (consentement par canal exigé), les autres sont opérationnels.
 */
final class Notifier
{
    public static function queue(int $clientId, string $body, ?string $channel = null, ?int $campaignId = null): void
    {
        MessageService::queueText($clientId, $body, $campaignId ? 'marketing' : 'operational', $channel, $campaignId);
    }
}
