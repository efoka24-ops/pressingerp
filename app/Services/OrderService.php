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
    public function create(array $in, array $photos = [], array $opts = []): int
    {
        // $opts (synchronisation hors-ligne) : number, tracking_token, created_at, agency_id, user_id, workstation_id, as_of, offline
        $createdTs = isset($opts['created_at']) ? (int)strtotime((string)$opts['created_at']) : time();
        $createdAt = date('Y-m-d H:i:s', $createdTs ?: time());
        $agencyId = (int)($opts['agency_id'] ?? Auth::agencyId());
        $userId = (int)($opts['user_id'] ?? Auth::id());
        $client = Database::one('SELECT * FROM clients WHERE id = ?', [(int)($in['client_id'] ?? 0)])
            ?? throw new \DomainException('Sélectionnez un client.');
        // Pas de client anonyme : nom complet et numéro valides exigés avant tout dépôt
        if ($err = ClientService::identifiableError($client)) {
            throw new \DomainException('Client non identifiable — ' . $err . ' Corrigez la fiche client avant de continuer.');
        }
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

        try {
            $quote = (new PricingService())->quote((int)$client['id'], $level, $lines, $delivery, $agencyId, $opts['as_of'] ?? null);
        } catch (PricingMissing $e) {
            Audit::log('pricing.missing', 'articles', $e->articleId, ['article' => $e->articleName, 'client' => (int)$client['id']]);
            throw $e;
        }

        // Photo obligatoire pour les pièces fragiles, endommagées ou de valeur (seuil paramétrable)
        foreach ($quote['lines'] as $l) {
            if ($l['photo_reason'] !== null && empty($photos[$l['line']])) {
                throw new \DomainException("Photo obligatoire pour « {$l['label']} » ({$l['photo_reason']}).");
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

        if (!empty($opts['offline']) && $onAccount) {
            throw new \DomainException('Client en compte : la réception hors-ligne est impossible (contrôle du plafond d\'encours requis).');
        }

        // Photos stockées une fois par ligne saisie
        $paths = [];
        foreach ($photos as $index => $file) {
            $paths[$index] = Uploads::image($file, 'piece');
        }

        $orderId = Database::transaction(function () use ($client, $level, $quote, $onAccount, $delivery, $address, $in, $paths, $opts, $createdTs, $createdAt, $agencyId, $userId): int {
            $number = $opts['number'] ?? Numbering::next('order', 'PR-%d-%06d');
            $token = $opts['tracking_token'] ?? bin2hex(random_bytes(16));
            $promised = $level->promisedAt($createdTs);
            $orderId = Database::insert('orders', [
                'number'           => $number,
                'tracking_token'   => $token,
                'client_id'        => $client['id'],
                'agency_id'        => $agencyId,
                'user_id'          => $userId ?: null,
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
                'created_at'       => $createdAt,
                'workstation_id'   => $opts['workstation_id'] ?? null,
                'offline_created_at' => isset($opts['workstation_id']) ? $createdAt : null,
                'synced_at'        => isset($opts['workstation_id']) ? now() : null,
            ]);

            foreach ($quote['lines'] as $i => $l) {
                $code = sprintf('%s-%02d', $number, $i + 1);
                $gid = Database::insert('garments', [
                    'order_id'   => $orderId,
                    'seq'        => $i + 1,
                    'code'       => $code,
                    'article_id' => $l['article_id'],
                    'treatment_id' => $l['treatment_id'] ? ((int)Database::value('SELECT id FROM treatments WHERE id = ? AND active = 1', [$l['treatment_id']]) ?: null) : null,
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
                WorkflowService::log($gid, Step::Reception, 'reception', isset($paths[$l['line']]) ? 'Photo jointe' : null, null, $createdAt);
            }

            $count = count($quote['lines']);
            MessageService::queueEvent('deposit', (int)$client['id'], ['numero' => $number, 'pieces' => $count . ' pièce' . ($count > 1 ? 's' : ''), 'date_promise' => date('d/m à H:i', strtotime($promised)), 'lien' => tracking_url($token)], $orderId);
            Audit::log('order.create', 'orders', $orderId, ['total' => $quote['total']] + (isset($opts['workstation_id']) ? ['hors_ligne' => true, 'poste' => (int)$opts['workstation_id']] : []));
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
            MessageService::queueEvent('ready', (int)$o['client_id'], [
                'numero' => $o['number'],
                'retrait' => $o['delivery_address'] ? 'Livraison en préparation.' : 'Vous pouvez passer la retirer.',
                'solde' => $balance > 0 ? ' Reste à payer : ' . money($balance, true) . '.' : '',
                'lien' => tracking_url($o['tracking_token']),
            ], (int)$orderId);
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
            QualityGate::assertOrderReady($orderId);   // RG8 : aucune pièce remise sans contrôle qualité valide
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
            $points = (int)Database::value('SELECT loyalty_points FROM clients WHERE id = ?', [$o['client_id']]);
            MessageService::queueEvent('closed', (int)$o['client_id'], ['numero' => $o['number'], 'fidelite' => $points > 0 ? "Vos points fidélité : $points." : ''], $orderId);
            Audit::log('order.pickup', 'orders', $orderId);
        });
    }

    /**
     * Remise accordée après la création de la commande. Exige l'autorisation d'un responsable, un motif, et ne peut
     * ramener le total sous ce qui est déjà encaissé. Le responsable d'agence est plafonné ; direction et administrateur non.
     */
    public function applyDiscount(int $orderId, int $amount, string $reason, array $authoriser): void
    {
        if (mb_strlen(trim($reason)) < 8) {
            throw new \DomainException('Motif détaillé obligatoire (8 caractères minimum).');
        }
        if ($amount <= 0) {
            throw new \DomainException('Montant de remise invalide.');
        }
        Database::transaction(function () use ($orderId, $amount, $reason, $authoriser): void {
            $o = Database::one('SELECT * FROM orders WHERE id = ?' . Auth::scopeSql() . ' FOR UPDATE', [$orderId]) ?? throw new \DomainException('Commande introuvable.');
            if (!in_array($o['status'], ['en_atelier', 'pret'], true)) {
                throw new \DomainException('Cette commande n\'accepte plus de remise.');
            }
            $total = (int)$o['total'];
            if ($authoriser['role'] === 'manager') {
                $max = (int)SettingsService::get('discount.max_pct');
                if ($amount > intdiv($total * $max, 100)) {
                    throw new \DomainException("Remise supérieure au plafond d'un responsable d'agence ($max % = " . money(intdiv($total * $max, 100)) . ' FCFA) : la direction doit l\'accorder.');
                }
            }
            $new = $total - $amount;
            if ($new < (int)$o['paid']) {
                throw new \DomainException('Cette remise ramènerait le total sous les sommes déjà encaissées : annulez d\'abord un paiement.');
            }
            Database::update('orders', [
                'discount' => (int)$o['discount'] + $amount, 'total' => $new,
                'discount_label' => mb_substr(trim(($o['discount_label'] ? $o['discount_label'] . ' + ' : '') . 'remise accordée'), 0, 100),
            ], 'id = :id', ['id' => $orderId]);
            Database::insert('order_adjustments', [
                'order_id' => $orderId, 'kind' => 'remise', 'amount' => $amount, 'old_total' => $total, 'new_total' => $new,
                'reason' => mb_substr(trim($reason), 0, 255), 'requested_by' => Auth::id() ?: null, 'authorised_by' => (int)$authoriser['id'], 'created_at' => now(),
            ]);
            Audit::log('order.discount', 'orders', $orderId, ['number' => $o['number'], 'authorised_by' => (int)$authoriser['id'], 'requested_by' => Auth::id()], ['total' => $total], ['total' => $new], $reason);
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
