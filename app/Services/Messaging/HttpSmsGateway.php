<?php
declare(strict_types=1);

namespace App\Services\Messaging;

use App\Core\Config;

/**
 * SMS par une API HTTP générique (JSON ou formulaire), à adapter au fournisseur retenu sans toucher au code :
 *   'sms' => [
 *     'url' => 'https://api.exemple.com/sms', 'headers' => ['Authorization: Bearer …'],
 *     'content_type' => 'application/json',
 *     'body' => '{"to":"{to}","message":"{message}","sender":"Pressing"}',
 *     'ok_codes' => [200, 201, 202],
 *   ]
 * {to} reçoit le numéro international sans « + » (237670123456), {message} le texte (échappé selon le type de contenu).
 */
final class HttpSmsGateway implements Gateway
{
    public function channel(): string
    {
        return 'sms';
    }

    public function configured(): bool
    {
        $c = (array)Config::get('sms', []);
        return !empty($c['url']) && !empty($c['body']);
    }

    public function send(string $to, string $subject, string $body): ?string
    {
        $c = (array)Config::get('sms', []);
        $json = str_contains((string)($c['content_type'] ?? 'application/json'), 'json');
        $esc = fn(string $v) => $json ? substr((string)json_encode($v, JSON_UNESCAPED_UNICODE), 1, -1) : rawurlencode($v);
        $payload = strtr((string)$c['body'], ['{to}' => $esc(ltrim($to, '+')), '{message}' => $esc($body)]);
        $ch = curl_init((string)$c['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: ' . ($c['content_type'] ?? 'application/json')], (array)($c['headers'] ?? [])),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('SMS : réseau (' . $err . ')');
        }
        if (!in_array($code, (array)($c['ok_codes'] ?? [200, 201, 202]), true)) {
            throw new \RuntimeException('SMS refusé par le fournisseur (HTTP ' . $code . ') : ' . mb_substr((string)$raw, 0, 120));
        }
        $data = json_decode((string)$raw, true);
        return is_array($data) ? (string)($data['id'] ?? $data['message_id'] ?? $data['messageId'] ?? '') ?: null : null;
    }
}
