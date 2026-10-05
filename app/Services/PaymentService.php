<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\PaymentMethod;

/**
 * Encaissements. Un paiement n'est jamais modifié ni supprimé : une annulation est une écriture inverse (montant négatif)
 * qui référence l'original, avec motif et autorisation d'un responsable.
 */
final class PaymentService
{
    public function record(int $clientId, PaymentMethod $method, int $amount, ?int $orderId = null, ?int $invoiceId = null, ?string $reference = null, bool $viaGateway = false, ?string $splitGroup = null, ?string $receiptNo = null, ?int $sessionId = null): int
    {
        if ($amount <= 0) {
            throw new \DomainException('Montant invalide.');
        }
        $session = $sessionId ? ['id' => $sessionId] : null;   // caisse imposée (ex. caisse du livreur)
        if (!$session && !$viaGateway && $method->needsCashSession()) {
            $session = (new CashService())->current(Auth::id());
            if (!$session) {
                throw new \DomainException('Ouvrez votre caisse avant d\'encaisser.');
            }
        }

        return Database::transaction(function () use ($clientId, $method, $amount, $orderId, $invoiceId, $reference, $session, $splitGroup, $receiptNo): int {
            if ($orderId !== null) {
                $o = Database::one('SELECT total, paid FROM orders WHERE id = ?' . Auth::scopeSql() . ' FOR UPDATE', [$orderId]) ?? throw new \DomainException('Commande introuvable.');
                $balance = (int)$o['total'] - (int)$o['paid'];
                if ($amount > $balance) {
                    throw new \DomainException('Montant supérieur au solde (' . money($balance, true) . ').');
                }
                Database::update('orders', ['paid' => (int)$o['paid'] + $amount], 'id = :id', ['id' => $orderId]);
            }
            if ($invoiceId !== null) {
                $i = Database::one('SELECT total, paid FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId]) ?? throw new \DomainException('Facture introuvable.');
                $balance = (int)$i['total'] - (int)$i['paid'];
                if ($amount > $balance) {
                    throw new \DomainException('Montant supérieur au solde de la facture (' . money($balance, true) . ').');
                }
                $paid = (int)$i['paid'] + $amount;
                Database::update('invoices', ['paid' => $paid, 'status' => $paid >= (int)$i['total'] ? 'payee' : 'partielle'], 'id = :id', ['id' => $invoiceId]);
            }
            $id = Database::insert('payments', [
                'client_id'       => $clientId,
                'order_id'        => $orderId,
                'invoice_id'      => $invoiceId,
                'cash_session_id' => $session['id'] ?? null,
                'method'          => $method->value,
                'amount'          => $amount,
                'reference'       => $reference ?: null,
                'user_id'         => Auth::id() ?: null,
                'created_at'      => now(),
                'kind'            => 'payment',
                'split_group'     => $splitGroup,
                'receipt_no'      => $receiptNo ?? Numbering::next('receipt', 'RC-%d-%06d'),
            ]);
            Audit::log('payment.create', 'payments', $id, ['amount' => $amount, 'method' => $method->value]);
            return $id;
        });
    }

    /**
     * Paiement mixte : plusieurs modes pour un même encaissement, sous un seul reçu. Tout ou rien.
     * @param list<array{method:string,amount:mixed,reference?:string}> $lines
     * @return array{receipt:string,ids:list<int>}
     */
    public function recordMixed(int $clientId, array $lines, ?int $orderId = null, ?int $invoiceId = null, ?int $sessionId = null): array
    {
        $clean = [];
        foreach ($lines as $l) {
            $amount = (int)($l['amount'] ?? 0);
            if ($amount === 0 && trim((string)($l['method'] ?? '')) === '') {
                continue; // ligne laissée vide
            }
            $method = PaymentMethod::tryFrom((string)($l['method'] ?? '')) ?? throw new \DomainException('Mode de paiement invalide.');
            if ($amount <= 0) {
                continue;
            }
            $clean[] = [$method, $amount, trim((string)($l['reference'] ?? '')) ?: null];
        }
        if (!$clean) {
            throw new \DomainException('Saisissez au moins un montant.');
        }
        return Database::transaction(function () use ($clientId, $clean, $orderId, $invoiceId, $sessionId): array {
            $receipt = Numbering::next('receipt', 'RC-%d-%06d');
            $group = count($clean) > 1 ? 'MX-' . strtoupper(bin2hex(random_bytes(4))) : null;
            $ids = [];
            foreach ($clean as [$method, $amount, $ref]) {
                $ids[] = $this->record($clientId, $method, $amount, $orderId, $invoiceId, $ref, false, $group, $receipt, $sessionId);
            }
            return ['receipt' => $receipt, 'ids' => $ids];
        });
    }

    /**
     * Annule un encaissement par une écriture inverse. Réservé aux responsables (autorisation tracée).
     * L'argent rendu sort de la caisse ouverte de l'agent qui exécute l'opération.
     */
    public function reverse(int $paymentId, string $reason, array $authoriser): int
    {
        if (mb_strlen(trim($reason)) < 8) {
            throw new \DomainException('Motif détaillé obligatoire (8 caractères minimum).');
        }
        return Database::transaction(function () use ($paymentId, $reason, $authoriser): int {
            $p = Database::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]) ?? throw new \DomainException('Paiement introuvable.');
            if ($p['kind'] !== 'payment' || (int)$p['amount'] <= 0) {
                throw new \DomainException('Seul un encaissement peut être annulé.');
            }
            if (Database::value('SELECT id FROM payments WHERE reverses_id = ?', [$paymentId])) {
                throw new \DomainException('Ce paiement a déjà été annulé.');
            }
            if ($p['invoice_id']) {
                throw new \DomainException('Règlement d\'une facture : à corriger par un avoir (module Commercial).');
            }
            $o = Database::one('SELECT * FROM orders WHERE id = ?' . Auth::scopeSql() . ' FOR UPDATE', [$p['order_id']]) ?? throw new \DomainException('Commande introuvable.');
            if (in_array($o['status'], ['retire', 'livre'], true)) {
                throw new \DomainException('Commande déjà remise au client : annulation impossible.');
            }
            $method = PaymentMethod::from($p['method']);
            $session = null;
            if ($method->needsCashSession()) {
                $session = (new CashService())->current(Auth::id()) ?? throw new \DomainException('Ouvrez votre caisse : le remboursement sort de votre tiroir.');
            }
            $amount = (int)$p['amount'];
            $id = Database::insert('payments', [
                'client_id' => $p['client_id'], 'order_id' => $p['order_id'], 'invoice_id' => null, 'cash_session_id' => $session['id'] ?? null,
                'method' => $p['method'], 'amount' => -$amount, 'reference' => $p['reference'], 'user_id' => Auth::id() ?: null, 'created_at' => now(),
                'kind' => 'reversal', 'reverses_id' => $paymentId, 'reason' => mb_substr(trim($reason), 0, 255), 'authorised_by' => (int)$authoriser['id'],
                'receipt_no' => Numbering::next('receipt', 'RC-%d-%06d'),
            ]);
            Database::update('orders', ['paid' => (int)$o['paid'] - $amount], 'id = :id', ['id' => $o['id']]);
            Audit::log('payment.reverse', 'payments', $paymentId, ['order' => $o['number'], 'amount' => $amount, 'method' => $p['method'], 'authorised_by' => (int)$authoriser['id'], 'requested_by' => Auth::id()], ['paid' => (int)$o['paid']], ['paid' => (int)$o['paid'] - $amount], $reason);
            return $id;
        });
    }
}
