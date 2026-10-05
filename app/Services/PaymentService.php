<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\PaymentMethod;

final class PaymentService
{
    public function record(int $clientId, PaymentMethod $method, int $amount, ?int $orderId = null, ?int $invoiceId = null, ?string $reference = null, bool $viaGateway = false): int
    {
        if ($amount <= 0) {
            throw new \DomainException('Montant invalide.');
        }
        $session = null;
        if (!$viaGateway && $method->needsCashSession()) {
            $session = (new CashService())->current(Auth::id());
            if (!$session) {
                throw new \DomainException('Ouvrez votre caisse avant d\'encaisser.');
            }
        }

        return Database::transaction(function () use ($clientId, $method, $amount, $orderId, $invoiceId, $reference, $session): int {
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
            ]);
            Audit::log('payment.create', 'payments', $id, ['amount' => $amount, 'method' => $method->value]);
            return $id;
        });
    }
}
