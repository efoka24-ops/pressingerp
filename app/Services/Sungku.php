<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/** Échec prouvé : Sungku a répondu clairement en refusant (4xx hors 429). */
final class SungkuRejected extends \RuntimeException {}

/** Échec non prouvé : délai dépassé, 5xx, 429 ou coupure réseau. L'argent a peut-être bougé. */
final class SungkuUnavailable extends \RuntimeException {}

/** Client HTTP de la passerelle Sungku (relais vers pawaPay) : initier un dépôt, vérifier un webhook. */
final class Sungku
{
    /** @return array<string,mixed> réponse de Sungku */
    public function initiateDeposit(array $body): array
    {
        $key = (string)Config::get('sungku.api_key');
        if ($key === '') {
            throw new \DomainException('Passerelle Mobile Money non configurée.');
        }
        $ch = curl_init(rtrim((string)Config::get('sungku.base_url'), '/') . '/api/partners/deposits');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'X-Api-Key: ' . $key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new SungkuUnavailable('Réseau : ' . $err);
        }
        if ($code === 429 || $code >= 500) {
            throw new SungkuUnavailable('HTTP ' . $code);
        }
        $data = json_decode((string)$raw, true);
        if ($code >= 400) {
            throw new SungkuRejected(is_array($data) ? (string)($data['message'] ?? $data['error'] ?? 'HTTP ' . $code) : 'HTTP ' . $code);
        }
        return is_array($data) ? $data : [];
    }

    /** Signature : sha256=HMAC("$timestamp.$corps", secret), comparée en temps constant, avec fenêtre anti-rejeu de 10 min. */
    public static function verifySignature(string $timestamp, string $rawBody, string $signature): bool
    {
        $secret = (string)Config::get('sungku.webhook_secret');
        if ($secret === '' || $timestamp === '' || $signature === '') {
            return false;
        }
        if (abs(time() - (int)$timestamp) > 600) {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret), $signature);
    }
}
