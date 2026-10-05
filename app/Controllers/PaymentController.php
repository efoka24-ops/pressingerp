<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Domain\PaymentMethod;
use App\Core\Database;
use App\Services\Authorizer;
use App\Services\MobileMoneyService;
use App\Services\PaymentService;
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

    /** Annulation d'un encaissement par écriture inverse, autorisée par un responsable. */
    public function reverse(string $id): void
    {
        $p = Database::one('SELECT p.order_id, o.agency_id FROM payments p LEFT JOIN orders o ON o.id = p.order_id WHERE p.id = ?', [(int)$id]) ?? $this->fail('Paiement introuvable.');
        try {
            $authoriser = Authorizer::fromRequest((int)$p['agency_id']);
            $revId = (new PaymentService())->reverse((int)$id, $this->str('reason'), $authoriser);
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Encaissement annulé par écriture inverse. Remboursez le client avec l\'avoir imprimé.', '/commandes/' . (int)$p['order_id'] . '?recu=' . $revId);
    }

    /** Reçu (ou avoir de caisse) d'un encaissement ; les lignes d'un paiement mixte sont regroupées. */
    public function receipt(string $id): void
    {
        $p = Database::one('SELECT * FROM payments WHERE id = ?', [(int)$id]) ?? throw new \App\Core\HttpException(404, 'Reçu introuvable');
        $where = $p['split_group'] ? 'p.split_group = ?' : 'p.id = ?';
        $lines = Database::all(
            "SELECT p.*, o.number, c.name client, u.name agent FROM payments p LEFT JOIN orders o ON o.id = p.order_id JOIN clients c ON c.id = p.client_id LEFT JOIN users u ON u.id = p.user_id WHERE $where ORDER BY p.id" . '',
            [$p['split_group'] ?: (int)$id]
        );
        $order = $p['order_id'] ? Database::one('SELECT total, paid, agency_id FROM orders WHERE id = ?' . Auth::scopeSql(), [$p['order_id']]) : null;
        if ($p['order_id'] && !$order) {
            throw new \App\Core\HttpException(404, 'Reçu introuvable');
        }
        $authoriser = $p['authorised_by'] ? Database::value('SELECT name FROM users WHERE id = ?', [$p['authorised_by']]) : null;
        $this->view('payments/receipt', [
            'lines' => $lines, 'order' => $order, 'authoriser' => $authoriser,
            'agency' => (string)Database::value('SELECT a.name FROM agencies a JOIN orders o ON o.agency_id = a.id WHERE o.id = ?', [$p['order_id']]),
        ], null);
    }

    /** Confirmation manuelle d'un paiement Mobile Money dont le webhook n'est pas arrivé (site sans HTTPS). */
    public function manualConfirm(string $id): void
    {
        $i = Database::one('SELECT i.order_id, o.agency_id FROM payment_intents i JOIN orders o ON o.id = i.order_id WHERE i.id = ?', [(int)$id]) ?? $this->fail('Demande introuvable.');
        try {
            $authoriser = Authorizer::fromRequest((int)$i['agency_id']);
            (new MobileMoneyService())->confirmManually((int)$id, $this->str('operator_ref'), $authoriser);
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Paiement Mobile Money confirmé manuellement et tracé.', '/commandes/' . (int)$i['order_id']);
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
