<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Domain\PaymentMethod;

/**
 * Paiement Mobile Money d'une commande via Sungku.
 * Le montant est calculé côté serveur. Un paiement n'est jamais « réussi » à l'initiation : seul le webhook confirme.
 */
final class MobileMoneyService
{
    /**
     * Libellé vu par le client sur son téléphone : la passerelle le limite à 22 caractères. Le numéro de commande est gardé en entier
     * (sans tirets) ; c'est le préfixe qui est raccourci si besoin, jamais le numéro.
     */
    public static function customerMessage(string $orderNumber): string
    {
        $ref = preg_replace('/[^A-Za-z0-9]/', '', $orderNumber) ?? '';
        $ref = substr($ref, -22);
        $room = 22 - strlen($ref) - 1;
        return $room >= 3 ? substr('Pressing', 0, $room) . ' ' . $ref : $ref;
    }

    /** @return array{intent:array,message:string} */
    public function initiate(int $orderId, PaymentMethod $method, string $phone): array
    {
        if (!in_array($method, [PaymentMethod::Orange, PaymentMethod::Mtn], true)) {
            throw new \DomainException('Mode de paiement mobile invalide (Orange Money ou MTN MoMo).');
        }
        $msisdn = self::normalizePhone($phone);

        $o = Database::one('SELECT id, number, client_id, total, paid, status FROM orders WHERE id = ?' . Auth::scopeSql(), [$orderId]) ?? throw new \DomainException('Commande introuvable.');
        if (in_array($o['status'], ['annule', 'retire', 'livre'], true)) {
            throw new \DomainException('Cette commande n\'accepte plus de paiement.');
        }
        $amount = (int)$o['total'] - (int)$o['paid'];
        if ($amount <= 0) {
            throw new \DomainException('Commande déjà soldée.');
        }
        if (Database::value("SELECT COUNT(*) FROM payment_intents WHERE order_id = ? AND status = 'PENDING' AND created_at > (NOW() - INTERVAL 30 MINUTE)", [$orderId])) {
            throw new \DomainException('Un paiement Mobile Money est déjà en attente pour cette commande.');
        }

        $reference = $o['number'] . '-' . strtoupper(bin2hex(random_bytes(3)));
        $id = Database::insert('payment_intents', [
            'reference' => $reference, 'order_id' => $orderId, 'client_id' => (int)$o['client_id'], 'method' => $method->value,
            'amount' => $amount, 'phone' => $msisdn, 'status' => 'PENDING', 'created_by' => Auth::id() ?: null, 'created_at' => now(),
        ]);
        Audit::log('momo.initiate', 'payment_intents', $id, ['order' => $o['number'], 'amount' => $amount, 'method' => $method->value]);

        try {
            $res = (new Sungku())->initiateDeposit([
                'amount'          => $amount,
                'currency'        => (string)Config::get('sungku.currency', 'XAF'),
                'phoneNumber'     => $msisdn,
                'reference'       => $reference,
                'description'     => 'Commande ' . $o['number'],
                'customerMessage' => self::customerMessage($o['number']),
                'metadata'        => ['order_id' => $orderId, 'intent_id' => $id],
            ]);
            Database::update('payment_intents', ['provider_ref' => isset($res['id']) ? substr((string)$res['id'], 0, 80) : null, 'updated_at' => now()], 'id = :id', ['id' => $id]);
            $message = 'Demande envoyée. Le client doit valider sur son téléphone.';
        } catch (SungkuRejected $e) {
            Database::update('payment_intents', ['status' => 'FAILED', 'provider_status' => substr($e->getMessage(), 0, 30), 'updated_at' => now()], 'id = :id', ['id' => $id]);
            throw new \DomainException('Paiement refusé par la passerelle : ' . $e->getMessage());
        } catch (SungkuUnavailable $e) {
            // Échec non prouvé : on laisse PENDING, le webhook tranchera.
            error_log('Sungku indisponible (' . $reference . ') : ' . $e->getMessage());
            $message = 'Passerelle indisponible : le paiement reste en attente de confirmation. Ne relancez pas avant 30 minutes.';
        }
        return ['intent' => Database::one('SELECT * FROM payment_intents WHERE id = ?', [$id]), 'message' => $message];
    }

    /**
     * Traite un webhook déjà authentifié. Idempotent : rejouer le même événement ne crée jamais deux paiements.
     * Retourne un libellé de résultat.
     */
    public function handleWebhook(array $payload): string
    {
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
        $reference = (string)($data['reference'] ?? '');
        $status = strtoupper((string)($data['status'] ?? ''));
        if ($reference === '') {
            return 'ignored:no-reference';
        }

        return Database::transaction(function () use ($reference, $status, $data): string {
            $i = Database::one('SELECT * FROM payment_intents WHERE reference = ? FOR UPDATE', [$reference]);
            if (!$i) {
                return 'ignored:unknown-reference';
            }
            if ($i['status'] === 'CONFIRMED') {
                return 'duplicate';
            }
            Database::update('payment_intents', ['provider_status' => substr($status, 0, 30), 'updated_at' => now()], 'id = :id', ['id' => $i['id']]);

            if ($status === 'CONFIRMED') {
                if (isset($data['amount']) && (int)$data['amount'] !== (int)$i['amount']) {
                    error_log("Sungku: montant incohérent pour $reference (attendu {$i['amount']}, reçu {$data['amount']})");
                    return 'ignored:amount-mismatch';
                }
                // L'argent a bougé : on enregistre même si l'intention avait été marquée FAILED.
                $paymentId = (new PaymentService())->record((int)$i['client_id'], PaymentMethod::from($i['method']), (int)$i['amount'], orderId: (int)$i['order_id'], reference: $reference, viaGateway: true);
                Database::update('payment_intents', ['status' => 'CONFIRMED', 'payment_id' => $paymentId], 'id = :id', ['id' => $i['id']]);
                return 'confirmed';
            }
            if (in_array($status, ['FAILED', 'REJECTED', 'CANCELLED', 'EXPIRED'], true)) {
                if ($i['status'] === 'PENDING') {
                    Database::update('payment_intents', ['status' => 'FAILED'], 'id = :id', ['id' => $i['id']]);
                }
                return 'failed';
            }
            if (!in_array($status, ['PENDING', 'ACCEPTED', 'PROCESSING', 'SUBMITTED'], true)) {
                error_log("Sungku: statut inconnu « $status » pour $reference");
                return 'ignored:unknown-status';
            }
            return 'pending';
        });
    }

    /**
     * Confirmation manuelle d'un paiement Mobile Money, quand la passerelle n'a pas pu livrer son webhook (site sans HTTPS).
     * Le responsable a vérifié la transaction sur le relevé de l'opérateur ; sa référence et son autorisation sont tracées.
     */
    public function confirmManually(int $intentId, string $operatorRef, array $authoriser): int
    {
        if (mb_strlen(trim($operatorRef)) < 6) {
            throw new \DomainException('Saisissez la référence de la transaction relevée chez l\'opérateur (6 caractères minimum).');
        }
        return Database::transaction(function () use ($intentId, $operatorRef, $authoriser): int {
            $i = Database::one('SELECT * FROM payment_intents WHERE id = ? FOR UPDATE', [$intentId]) ?? throw new \DomainException('Demande de paiement introuvable.');
            if ($i['status'] === 'CONFIRMED') {
                throw new \DomainException('Ce paiement est déjà confirmé.');
            }
            $paymentId = (new PaymentService())->record((int)$i['client_id'], PaymentMethod::from($i['method']), (int)$i['amount'], orderId: (int)$i['order_id'], reference: 'MANUEL ' . trim($operatorRef), viaGateway: true);
            Database::update('payment_intents', ['status' => 'CONFIRMED', 'provider_status' => 'MANUEL', 'payment_id' => $paymentId, 'updated_at' => now()], 'id = :id', ['id' => $intentId]);
            Audit::log('momo.manual_confirm', 'payment_intents', $intentId, ['reference' => trim($operatorRef), 'authorised_by' => (int)$authoriser['id'], 'requested_by' => Auth::id()], ['status' => $i['status']], ['status' => 'CONFIRMED'], 'Confirmation manuelle sur relevé opérateur');
            return $paymentId;
        });
    }

    /** Numéro camerounais : 6XXXXXXXX (9 chiffres) devient 2376XXXXXXXX. */
    public static function normalizePhone(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($d, '00237')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 9 && $d[0] === '6') {
            $d = '237' . $d;
        }
        if (!preg_match('/^2376\d{8}$/', $d)) {
            throw new \DomainException('Numéro invalide : saisissez un numéro camerounais (ex. 6XX XX XX XX).');
        }
        return $d;
    }
}
