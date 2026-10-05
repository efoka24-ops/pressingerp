<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\GarmentStatus;
use App\Domain\PaymentMethod;
use App\Domain\Role;
use App\Domain\ServiceLevel;
use App\Domain\Step;

final class OrderService
{
    /** Réception : crée la commande, une pièce par vêtement, et notifie le client. */
    public function create(array $in, array $photos = []): int
    {
        $client = Database::one('SELECT * FROM clients WHERE id = ?', [(int)($in['client_id'] ?? 0)])
            ?? throw new \DomainException('Sélectionnez un client.');
        $level = ServiceLevel::tryFrom((string)($in['service_level'] ?? '')) ?? ServiceLevel::Standard;
        $lines = array_filter((array)($in['lines'] ?? []), fn($l) => is_array($l) && !empty($l['article_id']));
        if (!$lines) {
            throw new \DomainException('Ajoutez au moins un article.');
        }
        $address = trim((string)($in['delivery_address'] ?? ''));
        $delivery = !empty($in['delivery']);
        if ($delivery && $address === '') {
            throw new \DomainException('Adresse de livraison obligatoire.');
        }

        $quote = (new PricingService())->quote((int)$client['id'], $level, $lines, $delivery);

        // Photo obligatoire pour les pièces fragiles ou endommagées
        foreach ($quote['lines'] as $l) {
            if (($l['fragile'] || $l['damages'] !== '') && empty($photos[$l['line']])) {
                throw new \DomainException("Photo obligatoire pour « {$l['label']} » (article fragile ou déjà endommagé).");
            }
        }

        // Clients pros sous contrat : en compte, avec contrôle du plafond d'encours
        $onAccount = $client['type'] === 'pro'
            && (bool)Database::value('SELECT COUNT(*) FROM contracts WHERE client_id = ? AND active = 1 AND CURDATE() BETWEEN start_date AND end_date', [$client['id']]);
        if ($onAccount && (int)$client['credit_limit'] > 0) {
            $outstanding = ClientService::outstanding((int)$client['id']);
            if ($outstanding + $quote['total'] > (int)$client['credit_limit'] && !Auth::isManager()) {
                throw new \DomainException('Plafond d\'encours dépassé (' . money($outstanding) . ' / ' . money($client['credit_limit']) . ') : validation d\'un responsable requise.');
            }
        }

        // Photos stockées une fois par ligne saisie
        $paths = [];
        foreach ($photos as $index => $file) {
            $paths[$index] = Uploads::image($file, 'piece');
        }

        $orderId = Database::transaction(function () use ($client, $level, $quote, $onAccount, $delivery, $address, $in, $paths): int {
            $number = Numbering::next('order', 'PR-%d-%06d');
            $token = bin2hex(random_bytes(16));
            $promised = $level->promisedAt();
            $orderId = Database::insert('orders', [
                'number'           => $number,
                'tracking_token'   => $token,
                'client_id'        => $client['id'],
                'agency_id'        => Auth::agencyId(),
                'user_id'          => Auth::id(),
                'service_level'    => $level->value,
                'status'           => 'en_atelier',
                'promised_at'      => $promised,
                'subtotal'         => $quote['subtotal'],
                'surcharge'        => $quote['surcharge'],
                'discount'         => $quote['discount'],
                'discount_label'   => $quote['discount_label'],
                'delivery_fee'     => $quote['delivery_fee'],
                'total'            => $quote['total'],
                'paid'             => 0,
                'on_account'       => $onAccount ? 1 : 0,
                'delivery_address' => $delivery ? $address : null,
                'notes'            => trim((string)($in['notes'] ?? '')) ?: null,
                'created_at'       => now(),
            ]);

            foreach ($quote['lines'] as $i => $l) {
                $code = sprintf('%s-%02d', $number, $i + 1);
                $gid = Database::insert('garments', [
                    'order_id'   => $orderId,
                    'seq'        => $i + 1,
                    'code'       => $code,
                    'article_id' => $l['article_id'],
                    'label'      => $l['label'],
                    'qty'        => $l['qty'],
                    'price'      => $l['price'],
                    'brand'      => $l['brand'] ?: null,
                    'color'      => $l['color'] ?: null,
                    'material'   => $l['material'] ?: null,
                    'damages'    => $l['damages'] ?: null,
                    'photo_path' => $paths[$l['line']] ?? null,
                    'step'       => Step::Tri->value,
                    'status'     => GarmentStatus::ATraiter->value,
                    'step_since' => now(),
                    'updated_at' => now(),
                ]);
                WorkflowService::log($gid, Step::Reception, 'reception', isset($paths[$l['line']]) ? 'Photo jointe' : null);
            }

            $count = count($quote['lines']);
            Notifier::queue((int)$client['id'], "Pressing : commande {$number} reçue ({$count} pièce" . ($count > 1 ? 's' : '') . '). Prête le ' . date('d/m à H:i', strtotime($promised)) . '. Suivi : ' . tracking_url($token));
            Audit::log('order.create', 'orders', $orderId, ['total' => $quote['total']]);
            return $orderId;
        });

        return $orderId;
    }

    /** Met à jour le statut de la commande selon l'avancement de ses pièces. */
    public function refreshStatus(int $orderId): void
    {
        $o = Database::one('SELECT * FROM orders WHERE id = ?' . Auth::scopeSql(), [$orderId]);
        if (!$o || in_array($o['status'], ['retire', 'livre', 'annule'], true)) {
            return;
        }
        $pending = (int)Database::value("SELECT COUNT(*) FROM garments WHERE order_id = ? AND step NOT IN ('pret', 'retire')", [$orderId]);
        if ($pending === 0 && $o['status'] !== 'pret') {
            Database::update('orders', ['status' => 'pret', 'ready_at' => now()], 'id = :id', ['id' => $orderId]);
            $balance = (int)$o['on_account'] ? 0 : (int)$o['total'] - (int)$o['paid'];
            $msg = "Pressing : votre commande {$o['number']} est prête"
                . ($o['delivery_address'] ? ', livraison en préparation.' : ', vous pouvez passer la retirer.')
                . ($balance > 0 ? ' Reste à payer : ' . money($balance, true) . '.' : '');
            Notifier::queue((int)$o['client_id'], $msg . ' Suivi : ' . tracking_url($o['tracking_token']));
        } elseif ($pending > 0 && $o['status'] === 'pret') {
            Database::update('orders', ['status' => 'en_atelier', 'ready_at' => null], 'id = :id', ['id' => $orderId]);
        }
    }

    /** Retrait (ou livraison) : encaisse le solde, clôt les pièces, crédite les points fidélité. */
    public function pickup(int $orderId, ?PaymentMethod $method): void
    {
        Database::transaction(function () use ($orderId, $method): void {
            $o = Database::one('SELECT * FROM orders WHERE id = ?' . Auth::scopeSql() . ' FOR UPDATE', [$orderId])
                ?? throw new \DomainException('Commande introuvable.');
            if ($o['status'] !== 'pret') {
                throw new \DomainException('La commande n\'est pas encore prête.');
            }
            $balance = (int)$o['on_account'] ? 0 : (int)$o['total'] - (int)$o['paid'];
            if ($balance > 0) {
                if (!$method) {
                    throw new \DomainException('Choisissez le mode de paiement du solde (' . money($balance, true) . ').');
                }
                (new PaymentService())->record((int)$o['client_id'], $method, $balance, orderId: $orderId);
            }
            Database::update('orders', [
                'status'       => $o['delivery_address'] ? 'livre' : 'retire',
                'picked_up_at' => now(),
                'picked_up_by' => Auth::id() ?: null,
            ], 'id = :id', ['id' => $orderId]);
            foreach (Database::all('SELECT id FROM garments WHERE order_id = ?', [$orderId]) as $g) {
                Database::update('garments', ['step' => Step::Retire->value, 'status' => GarmentStatus::Termine->value, 'updated_at' => now()], 'id = :id', ['id' => $g['id']]);
                WorkflowService::log((int)$g['id'], Step::Retire, 'retrait');
            }
            LoyaltyService::award((int)$o['client_id'], (int)$o['total']);
            Audit::log('order.pickup', 'orders', $orderId);
        });
    }

    public function cancel(int $orderId, string $reason): void
    {
        if (!Auth::isManager()) {
            throw new \DomainException('Annulation réservée à un responsable.');
        }
        $o = Database::one('SELECT * FROM orders WHERE id = ?' . Auth::scopeSql(), [$orderId]) ?? throw new \DomainException('Commande introuvable.');
        if (!in_array($o['status'], ['en_atelier', 'pret'], true)) {
            throw new \DomainException('Cette commande ne peut plus être annulée.');
        }
        if ((int)$o['paid'] > 0) {
            throw new \DomainException('Des paiements existent : effectuez le remboursement avant d\'annuler.');
        }
        if ($reason === '') {
            throw new \DomainException('Motif d\'annulation obligatoire.');
        }
        Database::update('orders', ['status' => 'annule', 'notes' => trim(($o['notes'] ?? '') . "\nAnnulée : " . $reason)], 'id = :id', ['id' => $orderId]);
        Audit::log('order.cancel', 'orders', $orderId, [], ['status' => $o['status']], ['status' => 'annule'], $reason);
    }
}
