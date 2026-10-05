<?php
declare(strict_types=1);

namespace App\Services\Messaging;

use App\Core\Config;

/**
 * WhatsApp Business Cloud API (Meta). Configuration : 'whatsapp' => ['phone_id' => '…', 'token' => '…', 'template' => 'nom_du_modele', 'lang' => 'fr'].
 * Limite imposée par Meta : un texte libre n'est accepté que dans les 24 h suivant un message du client. Pour écrire
 * le premier, un modèle approuvé est obligatoire : renseigner 'template' (le corps du message y est passé en {{1}}).
 */
final class WhatsAppGateway implements Gateway
{
    public function channel(): string
    {
        return 'whatsapp';
    }

    public function configured(): bool
    {
        $c = (array)Config::get('whatsapp', []);
        return !empty($c['phone_id']) && !empty($c['token']);
    }

    public function send(string $to, string $subject, string $body): ?string
    {
        $c = (array)Config::get('whatsapp', []);
        $message = !empty($c['template'])
            ? ['type' => 'template', 'template' => ['name' => $c['template'], 'language' => ['code' => $c['lang'] ?? 'fr'], 'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($body, 0, 1024)]]]]]]
            : ['type' => 'text', 'text' => ['body' => $body]];
        $ch = curl_init('https://graph.facebook.com/v19.0/' . rawurlencode((string)$c['phone_id']) . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['token']],
            CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp', 'to' => ltrim($to, '+')] + $message, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('WhatsApp : réseau (' . $err . ')');
        }
        $data = json_decode((string)$raw, true);
        if ($code >= 300) {
            throw new \RuntimeException('WhatsApp refusé (HTTP ' . $code . ') : ' . mb_substr((string)($data['error']['message'] ?? $raw), 0, 140));
        }
        return (string)($data['messages'][0]['id'] ?? '') ?: null;
    }
}
