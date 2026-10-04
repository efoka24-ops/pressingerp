<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Domain\PaymentMethod;
use App\Services\MobileMoneyService;
use App\Services\Sungku;

final class PaymentController extends Controller
{
    /** Comptoir : lance un paiement Mobile Money pour le solde de la commande. */
    public function initiate(string $id): void
    {
        $method = PaymentMethod::tryFrom($this->str('method')) ?? $this->fail('Mode de paiement invalide.');
        try {
            $res = (new MobileMoneyService())->initiate((int)$id, $method, $this->str('phone'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok($res['message'], '/commandes/' . (int)$id);
    }

    /** POST /payments/webhook/sungku : authentifié par signature HMAC, sans session. */
    public function sungkuWebhook(): void
    {
        $raw = (string)file_get_contents('php://input');
        $ok = Sungku::verifySignature(
            (string)($_SERVER['HTTP_X_APISUNGKU_TIMESTAMP'] ?? ''),
            $raw,
            (string)($_SERVER['HTTP_X_APISUNGKU_SIGNATURE'] ?? '')
        );
        if (!$ok) {
            $this->json(['error' => 'invalid signature'], 401);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->json(['error' => 'invalid body'], 400);
        }
        $result = (new MobileMoneyService())->handleWebhook($payload);
        $this->json(['ok' => true, 'result' => $result]);
    }
}
