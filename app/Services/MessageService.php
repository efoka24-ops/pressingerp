<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\Messaging\Gateways;

/**
 * Messages sortants vers les clients. Chaque message suit une chaîne de canaux (préféré → SMS → WhatsApp → e-mail) :
 * deux essais par canal (le second 5 minutes plus tard), puis repli sur le canal suivant ; si tous échouent, une alerte
 * demande de vérifier les coordonnées du client. Un canal non configuré est ignoré, jamais compté comme « envoyé ».
 */
final class MessageService
{
    /** Variables autorisées par modèle. */
    public const EVENTS = [
        'deposit'          => ['numero', 'pieces', 'date_promise', 'lien'],
        'ready'            => ['numero', 'retrait', 'solde', 'lien'],
        'late'             => ['numero', 'date_promise', 'lien'],
        'delivery_started' => ['numero', 'creneau', 'code', 'livreur', 'lien'],
        'delivery_failed'  => ['numero', 'motif', 'creneau'],
        'closed'           => ['numero', 'fidelite'],
    ];

    private const RETRY_MINUTES = [1 => 5];   // après le 1er échec d'un canal, nouvel essai 5 min plus tard ; au 2e échec, repli
    private const GIVE_UP_HOURS = 24;

    public static function render(string $body, array $vars): string
    {
        return trim((string)preg_replace('/\s{2,}/', ' ', preg_replace_callback('/\{(\w+)\}/', fn($m) => (string)($vars[$m[1]] ?? ''), $body)));
    }

    /** Message opérationnel d'après un modèle. @return ?int null si le modèle est désactivé ou le message déjà envoyé pour cette commande */
    public static function queueEvent(string $event, int $clientId, array $vars, ?int $orderId = null, bool $once = false): ?int
    {
        $t = Database::one('SELECT * FROM message_templates WHERE event = ?', [$event]);
        if (!$t || !(int)$t['active']) {
            return null;
        }
        if ($once && $orderId && Database::value("SELECT id FROM messages WHERE event = ? AND order_id = ? AND status <> 'annule' LIMIT 1", [$event, $orderId])) {
            return null;
        }
        return self::queueText($clientId, self::render((string)$t['body'], $vars), 'operational', null, null, $event, $orderId, 'Pressing : ' . $t['label']);
    }

    /**
     * @param string $purpose 'operational' (toujours) ou 'marketing' (consentement par canal obligatoire)
     * @return ?int null pour une offre refusée faute de consentement
     */
    public static function queueText(int $clientId, string $body, string $purpose = 'operational', ?string $preferred = null, ?int $campaignId = null, ?string $event = null, ?int $orderId = null, ?string $subject = null): ?int
    {
        $client = Database::one('SELECT * FROM clients WHERE id = ?', [$clientId]) ?? throw new \DomainException('Client introuvable.');
        $chain = self::chain($client, $purpose, $preferred);
        $row = [
            'client_id' => $clientId, 'body' => $body, 'campaign_id' => $campaignId, 'purpose' => $purpose, 'event' => $event, 'order_id' => $orderId,
            'subject' => $subject !== null ? mb_substr($subject, 0, 150) : null, 'status' => 'en_attente', 'created_at' => now(),
        ];
        if (!$chain) {
            if ($purpose === 'marketing') {
                return null;   // RG18 : pas de consentement (ou pas de contact valide) = pas d'offre
            }
            $id = Database::insert('messages', array_merge($row, ['channel' => $preferred ?? (string)$client['preferred_channel'], 'status' => 'echec', 'error' => 'Aucune coordonnée valide']));
            self::alertFailure($id, $client, $orderId, 'aucune coordonnée valide (SE10)');
            return $id;
        }
        return Database::insert('messages', $row + ['channel' => $chain[0], 'chain' => implode(',', array_slice($chain, 1)) ?: null]);
    }

    /** @return list<string> canaux utilisables pour ce client, dans l'ordre d'essai */
    public static function chain(array $client, string $purpose, ?string $preferred = null): array
    {
        $phoneOk = ClientService::phoneError(ClientService::normalizePhone((string)$client['phone'])) === null;
        $emailOk = !empty($client['email']) && filter_var($client['email'], FILTER_VALIDATE_EMAIL);
        $out = [];
        foreach (array_unique(array_filter([$preferred ?? $client['preferred_channel'], 'sms', 'whatsapp', 'email'])) as $ch) {
            $reachable = $ch === 'email' ? $emailOk : $phoneOk;
            if ($reachable && ($purpose !== 'marketing' || ConsentService::granted((int)$client['id'], $ch))) {
                $out[] = $ch;
            }
        }
        return $out;
    }

    /** Envoie les messages dus. @return array{sent:int,failed:int,retry:int,waiting:int} */
    public static function dispatch(int $limit = 50, ?int $now = null): array
    {
        $now ??= time();
        $out = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'waiting' => 0];
        $rows = Database::all(
            "SELECT m.*, c.phone, c.email, c.name client_name FROM messages m JOIN clients c ON c.id = m.client_id
             WHERE m.status = 'en_attente' AND (m.next_try_at IS NULL OR m.next_try_at <= ?) ORDER BY m.id LIMIT " . (int)$limit,
            [date('Y-m-d H:i:s', $now)]
        );
        foreach ($rows as $m) {
            $out[self::attempt($m, $now)]++;
        }
        return $out;
    }

    /** @return string sent | failed | retry | waiting */
    private static function attempt(array $m, int $now): string
    {
        $channels = array_values(array_filter(array_merge([$m['channel']], $m['chain'] ? explode(',', (string)$m['chain']) : [])));
        $failedOnce = false;
        while ($channels) {
            $ch = $channels[0];
            $gw = Gateways::for($ch);
            if (!$gw || !$gw->configured()) {
                self::log((int)$m['id'], $ch, 'non_configure', null);
                array_shift($channels);
                continue;
            }
            try {
                $ref = $gw->send($ch === 'email' ? (string)$m['email'] : (string)$m['phone'], (string)($m['subject'] ?? 'Pressing'), (string)$m['body']);
                self::log((int)$m['id'], $ch, 'envoye', null);
                Database::update('messages', ['status' => 'envoye', 'channel' => $ch, 'chain' => implode(',', array_slice($channels, 1)) ?: null, 'sent_at' => date('Y-m-d H:i:s', $now), 'error' => null, 'next_try_at' => null, 'provider_ref' => $ref ? mb_substr($ref, 0, 80) : null], 'id = :id', ['id' => $m['id']]);
                AlertService::safe(fn() => AlertService::close('msgfail:' . $m['id'], 'message remis', $now));
                return 'sent';
            } catch (\Throwable $e) {
                $failedOnce = true;
                self::log((int)$m['id'], $ch, 'echec', $e->getMessage());
                $failures = (int)Database::value("SELECT COUNT(*) FROM message_attempts WHERE message_id = ? AND channel = ? AND status = 'echec'", [$m['id'], $ch]);
                if (isset(self::RETRY_MINUTES[$failures])) {
                    Database::update('messages', ['channel' => $ch, 'chain' => implode(',', array_slice($channels, 1)) ?: null, 'next_try_at' => date('Y-m-d H:i:s', $now + self::RETRY_MINUTES[$failures] * 60), 'error' => mb_substr($e->getMessage(), 0, 255)], 'id = :id', ['id' => $m['id']]);
                    return 'retry';
                }
                array_shift($channels);   // repli sur le canal suivant
            }
        }
        // Plus aucun canal utilisable
        $age = $now - strtotime((string)$m['created_at']);
        if ($failedOnce || $age > self::GIVE_UP_HOURS * 3600) {
            Database::update('messages', ['status' => 'echec', 'error' => $failedOnce ? 'Tous les canaux ont échoué' : 'Aucun canal d\'envoi configuré', 'next_try_at' => null], 'id = :id', ['id' => $m['id']]);
            $client = Database::one('SELECT * FROM clients WHERE id = ?', [$m['client_id']]) ?? [];
            self::alertFailure((int)$m['id'], $client, $m['order_id'] ? (int)$m['order_id'] : null, $failedOnce ? 'tous les canaux ont échoué' : 'aucun canal configuré', $now);
            return 'failed';
        }
        Database::update('messages', ['error' => 'Aucun canal d\'envoi configuré', 'next_try_at' => date('Y-m-d H:i:s', $now + 15 * 60)], 'id = :id', ['id' => $m['id']]);
        return 'waiting';
    }

    private static function log(int $messageId, string $channel, string $status, ?string $error): void
    {
        Database::insert('message_attempts', ['message_id' => $messageId, 'channel' => $channel, 'status' => $status, 'error' => $error !== null ? mb_substr($error, 0, 255) : null, 'created_at' => now()]);
    }

    /** SE10 / SE11 : le client est injoignable, la réception doit vérifier sa fiche. */
    private static function alertFailure(int $messageId, array $client, ?int $orderId, string $why, ?int $now = null): void
    {
        AlertService::safe(function () use ($messageId, $client, $orderId, $why, $now): void {
            $agency = $orderId ? Database::value('SELECT agency_id FROM orders WHERE id = ?', [$orderId]) : null;
            AlertService::raise('message_failed', 'msgfail:' . $messageId, 'Message non remis à ' . ($client['name'] ?? 'un client') . ' (' . $why . ') : vérifier ses coordonnées.', 'client', isset($client['id']) ? (int)$client['id'] : null, $agency ? (int)$agency : null, null, $now);
        });
    }

    /** Messages en attente d'un canal : à signaler à l'administrateur. */
    public static function stuckCount(?int $now = null): int
    {
        return (int)Database::value("SELECT COUNT(*) FROM messages WHERE status = 'en_attente' AND created_at < ? AND error LIKE 'Aucun canal%'", [date('Y-m-d H:i:s', ($now ?? time()) - 1800)]);
    }

    /**
     * Envoi au fil des pages, quand aucun cron n'est configuré : après l'envoi de la réponse au navigateur,
     * au plus une fois par minute, par petits lots. Ne ralentit jamais la page.
     */
    public static function lazyDispatch(): void
    {
        try {
            $file = BASE_PATH . '/storage/messages.tick';
            if (is_file($file) && time() - (int)filemtime($file) < 60) {
                return;
            }
            @mkdir(dirname($file), 0750, true);
            @touch($file);
            register_shutdown_function(static function (): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                try {
                    self::dispatch(10);
                } catch (\Throwable $e) {
                    error_log('Messages : ' . $e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            error_log('Messages : ' . $e->getMessage());
        }
    }
}
