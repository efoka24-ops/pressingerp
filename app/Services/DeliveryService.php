<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\Role;

/**
 * Collecte et livraison à domicile.
 * Parcours : à collecter → collecté → en traitement → à livrer → en route → livré (ou non livré, replanifié).
 * RG20 : aucune livraison n'est clôturée sans preuve (code remis au client, signature ou photo).
 * RG21 : le solde est encaissé par le livreur ou explicitement reporté (client en compte, ou report autorisé par un responsable).
 */
final class DeliveryService
{
    public const STATUS = [
        'a_collecter'   => 'À collecter',
        'collecte'      => 'Collecté',
        'en_traitement' => 'En traitement',
        'a_livrer'      => 'À livrer',
        'en_route'      => 'En route',
        'livre'         => 'Livré',
        'non_livre'     => 'Non livré',
    ];

    public const FAIL_REASONS = ['absent' => 'Client absent', 'adresse' => 'Adresse introuvable ou erronée', 'refus' => 'Livraison refusée', 'autre' => 'Autre raison'];
    public const MAX_ATTEMPTS = 3;
    private const COURIER_METHODS = ['especes', 'orange', 'mtn'];

    // ---------------------------------------------------------------- Création

    /** Demande de collecte au domicile du client (les vêtements viennent à l'atelier). */
    public function createCollect(int $clientId, string $address, string $slot, string $notes = ''): int
    {
        $client = Database::one('SELECT * FROM clients WHERE id = ?', [$clientId]) ?? throw new \DomainException('Client introuvable.');
        if ($err = ClientService::identifiableError($client)) {
            throw new \DomainException('Client non identifiable — ' . $err);
        }
        $address = trim($address);
        if (mb_strlen($address) < 5) {
            throw new \DomainException('Adresse précise obligatoire (quartier, rue, repère).');
        }
        $slotAt = self::futureSlot($slot, true);
        $id = Database::insert('deliveries', [
            'kind' => 'collect', 'client_id' => $clientId, 'agency_id' => Auth::agencyId(), 'status' => 'a_collecter', 'address' => mb_substr($address, 0, 255),
            'phone' => $client['phone'], 'slot_at' => $slotAt, 'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null, 'created_by' => Auth::id() ?: null, 'created_at' => now(),
        ]);
        self::event($id, 'a_collecter', 'Demande de collecte');
        Audit::log('delivery.create', 'deliveries', $id, ['kind' => 'collect']);
        return $id;
    }

    /** Crée (ou retrouve) la livraison d'une commande qui demande une livraison à domicile. */
    public static function ensureForOrder(int $orderId): ?int
    {
        $o = Database::one('SELECT o.*, c.phone FROM orders o JOIN clients c ON c.id = o.client_id WHERE o.id = ?', [$orderId]);
        if (!$o || !$o['delivery_address']) {
            return null;
        }
        $existing = Database::value("SELECT id FROM deliveries WHERE order_id = ? AND kind = 'deliver' ORDER BY id DESC LIMIT 1", [$orderId]);
        if ($existing) {
            return (int)$existing;
        }
        $id = Database::insert('deliveries', [
            'kind' => 'deliver', 'order_id' => $orderId, 'client_id' => $o['client_id'], 'agency_id' => $o['agency_id'], 'status' => $o['status'] === 'pret' ? 'a_livrer' : 'en_traitement',
            'address' => $o['delivery_address'], 'phone' => $o['phone'], 'amount_due' => (int)$o['on_account'] ? 0 : max(0, (int)$o['total'] - (int)$o['paid']), 'created_by' => Auth::id() ?: null, 'created_at' => now(),
        ]);
        self::event($id, $o['status'] === 'pret' ? 'a_livrer' : 'en_traitement', 'Livraison demandée');
        return $id;
    }

    /** Rattache la commande créée à la réception à la collecte qui l'a précédée : le vêtement repart par la même livraison. */
    public static function linkCollectToOrder(int $collectId, int $orderId): void
    {
        $d = Database::one("SELECT * FROM deliveries WHERE id = ? AND kind = 'collect'", [$collectId]) ?? throw new \DomainException('Collecte introuvable.');
        if ($d['status'] !== 'collecte') {
            throw new \DomainException('Cette collecte n\'est pas encore marquée « collectée » par le livreur.');
        }
        Database::update('deliveries', ['order_id' => $orderId, 'status' => 'en_traitement'], 'id = :id', ['id' => $collectId]);
        self::event($collectId, 'en_traitement', 'Commande ' . Database::value('SELECT number FROM orders WHERE id = ?', [$orderId]) . ' créée');
    }

    /** Appelé quand l'état d'une commande change : une commande prête se met « à livrer », une commande reprise redevient « en traitement ». */
    public static function syncFromOrder(int $orderId): void
    {
        $o = Database::one('SELECT status FROM orders WHERE id = ?', [$orderId]);
        $d = Database::one("SELECT * FROM deliveries WHERE order_id = ? AND kind = 'deliver' AND status IN ('en_traitement', 'a_livrer') ORDER BY id DESC LIMIT 1", [$orderId]);
        if (!$o || !$d) {
            return;
        }
        $to = $o['status'] === 'pret' ? 'a_livrer' : 'en_traitement';
        if ($to !== $d['status'] && in_array($o['status'], ['pret', 'en_atelier'], true)) {
            Database::update('deliveries', ['status' => $to], 'id = :id', ['id' => $d['id']]);
            self::event((int)$d['id'], $to, $to === 'a_livrer' ? 'Commande prête' : 'Pièce remise en traitement');
        }
    }

    // ---------------------------------------------------------------- Affectation (comptoir, responsable)

    public function assign(int $id, int $driverId, string $slot): void
    {
        $d = $this->load($id);
        if (!Auth::can('delivery', 'update') || Auth::role() === Role::Livreur) {
            throw new \DomainException('L\'affectation est réservée à la réception et aux responsables.');
        }
        if (!in_array($d['status'], ['a_collecter', 'en_traitement', 'a_livrer', 'non_livre'], true)) {
            throw new \DomainException('Cette livraison ne peut plus être affectée (' . self::STATUS[$d['status']] . ').');
        }
        $driver = Database::one("SELECT id, name FROM users WHERE id = ? AND role = 'livreur' AND active = 1", [$driverId]) ?? throw new \DomainException('Choisissez un livreur actif.');
        $slotAt = self::futureSlot($slot, true);
        Database::update('deliveries', ['driver_id' => $driverId, 'slot_at' => $slotAt], 'id = :id', ['id' => $id]);
        self::event($id, $d['status'], 'Affectée à ' . $driver['name'] . ' · ' . $slotAt);
        Audit::log('delivery.assign', 'deliveries', $id, ['driver' => $driver['name']], ['driver_id' => $d['driver_id'], 'slot' => $d['slot_at']], ['driver_id' => $driverId, 'slot' => $slotAt]);
        AlertService::safe(fn() => AlertService::close('dlv:' . $id, 'livreur affecté'));
    }

    /** Correction d'une adresse introuvable (SE21) par la réception, après avoir joint le client. */
    public function updateAddress(int $id, string $address, string $reason): void
    {
        $d = $this->load($id);
        if (!Auth::can('delivery', 'update') || Auth::role() === Role::Livreur) {
            throw new \DomainException('La correction d\'adresse est réservée à la réception et aux responsables.');
        }
        $address = trim($address);
        if (mb_strlen($address) < 5 || mb_strlen(trim($reason)) < 5) {
            throw new \DomainException('Nouvelle adresse précise et motif obligatoires.');
        }
        Database::transaction(function () use ($d, $id, $address, $reason): void {
            Database::update('deliveries', ['address' => mb_substr($address, 0, 255)], 'id = :id', ['id' => $id]);
            if ($d['order_id']) {
                Database::update('orders', ['delivery_address' => mb_substr($address, 0, 255)], 'id = :id', ['id' => $d['order_id']]);
            }
            self::event($id, $d['status'], 'Adresse corrigée : ' . $address);
            Audit::log('delivery.address', 'deliveries', $id, [], ['address' => $d['address']], ['address' => $address], $reason);
        });
        AlertService::safe(fn() => AlertService::close('dlvaddr:' . $id, 'adresse corrigée'));
    }

    // ---------------------------------------------------------------- Livreur

    public function markCollected(int $id): void
    {
        $d = $this->load($id);
        $this->actor($d);
        if ($d['kind'] !== 'collect' || $d['status'] !== 'a_collecter') {
            throw new \DomainException('Cette demande n\'est pas une collecte à effectuer.');
        }
        Database::update('deliveries', ['status' => 'collecte'], 'id = :id', ['id' => $id]);
        self::event($id, 'collecte', 'Vêtements collectés');
    }

    /** « Partir » : la livraison passe en route et le client reçoit son code de remise. */
    public function start(int $id): void
    {
        Database::transaction(function () use ($id): void {
            $d = $this->load($id, true);
            $this->actor($d);
            if ($d['kind'] !== 'deliver' || !in_array($d['status'], ['a_livrer', 'non_livre'], true)) {
                throw new \DomainException('Cette livraison ne peut pas partir (' . self::STATUS[$d['status']] . ').');
            }
            if (!$d['driver_id']) {
                throw new \DomainException('Affectez d\'abord un livreur.');
            }
            $o = Database::one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$d['order_id']]) ?? throw new \DomainException('Commande introuvable.');
            if ($o['status'] !== 'pret') {
                throw new \DomainException('La commande n\'est pas prête : toutes les pièces doivent avoir passé le contrôle qualité.');
            }
            $code = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $due = (int)$o['on_account'] ? 0 : max(0, (int)$o['total'] - (int)$o['paid']);
            Database::update('deliveries', ['status' => 'en_route', 'otp_hash' => password_hash($code, PASSWORD_DEFAULT), 'amount_due' => $due, 'attempts' => (int)$d['attempts'] + 1], 'id = :id', ['id' => $id]);
            self::event($id, 'en_route', 'Départ du livreur · tentative ' . ((int)$d['attempts'] + 1));
            $driver = (string)Database::value('SELECT name FROM users WHERE id = ?', [$d['driver_id']]);
            MessageService::queueEvent('delivery_started', (int)$d['client_id'], [
                'numero' => $o['number'], 'creneau' => $d['slot_at'] ? 'vers ' . date('H:i', strtotime($d['slot_at'])) : 'dans la journée',
                'code' => $code, 'livreur' => $driver, 'lien' => tracking_url($o['tracking_token']),
            ], (int)$o['id']);
            AlertService::safe(fn() => AlertService::close('dlvfail:' . $id, 'nouvelle tentative'));
        });
    }

    /**
     * Clôture la livraison.
     * @param array{code?:string,signature?:string,photo?:array} $proof  au moins une preuve valide (RG20)
     * @param array{lines?:array,defer?:bool,reason?:string,authoriser?:array} $pay  encaissement du solde ou report explicite (RG21)
     */
    public function complete(int $id, array $proof, array $pay = []): void
    {
        $photoPath = !empty($proof['photo']) ? Uploads::image($proof['photo'], 'livraison') : null;
        $signaturePath = !empty($proof['signature']) ? self::saveSignature((string)$proof['signature']) : null;
        Database::transaction(function () use ($id, $proof, $pay, $photoPath, $signaturePath): void {
            $d = $this->load($id, true);
            $this->actor($d);
            if ($d['status'] !== 'en_route') {
                throw new \DomainException('Seule une livraison « en route » peut être clôturée.');
            }
            $o = Database::one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$d['order_id']]) ?? throw new \DomainException('Commande introuvable.');

            // RG20 : preuve obligatoire
            $proofs = [];
            $code = trim((string)($proof['code'] ?? ''));
            if ($code !== '') {
                if (!$d['otp_hash'] || !password_verify($code, (string)$d['otp_hash'])) {
                    throw new \DomainException('Code de remise incorrect : demandez-le au client (reçu par message).');
                }
                $proofs[] = ['code', 'code client validé'];
            }
            if ($signaturePath) {
                $proofs[] = ['signature', $signaturePath];
            }
            if ($photoPath) {
                $proofs[] = ['photo', $photoPath];
            }
            if (!$proofs) {
                throw new \DomainException('Preuve de livraison obligatoire : code du client, signature ou photo.');
            }

            // RG21 : solde encaissé ou reporté explicitement
            $payments = $this->settle($d, $o, $pay);

            foreach ($proofs as [$kind, $data]) {
                Database::insert('delivery_proofs', ['delivery_id' => $id, 'kind' => $kind, 'data' => $data, 'recorded_by' => Auth::id() ?: null, 'created_at' => now()]);
            }
            Database::update('deliveries', ['status' => 'livre', 'delivered_at' => now(), 'deferred' => $payments['deferred'] ? 1 : 0], 'id = :id', ['id' => $id]);
            self::event($id, 'livre', 'Livrée · preuve : ' . implode(', ', array_column($proofs, 0)) . ($payments['collected'] ? ' · encaissé ' . money($payments['collected']) . ' FCFA' : '') . ($payments['deferred'] ? ' · solde reporté' : ''));
            (new OrderService())->finish($o, 'livre');
        });
    }

    /** Livraison non aboutie : motif, nouveau créneau, client prévenu, alertes (SE20, SE21). */
    public function fail(int $id, string $reason, string $note, string $newSlot): void
    {
        if (!isset(self::FAIL_REASONS[$reason])) {
            throw new \DomainException('Choisissez le motif de l\'échec.');
        }
        $slotAt = self::futureSlot($newSlot, true);
        Database::transaction(function () use ($id, $reason, $note, $slotAt): void {
            $d = $this->load($id, true);
            $this->actor($d);
            if ($d['status'] !== 'en_route') {
                throw new \DomainException('Seule une livraison « en route » peut être déclarée non aboutie.');
            }
            Database::update('deliveries', ['status' => 'non_livre', 'slot_at' => $slotAt, 'otp_hash' => null], 'id = :id', ['id' => $id]);
            self::event($id, 'non_livre', self::FAIL_REASONS[$reason] . ($note !== '' ? ' — ' . $note : '') . ' · nouvelle tentative ' . $slotAt);
            $o = Database::one('SELECT * FROM orders WHERE id = ?', [$d['order_id']]);
            MessageService::queueEvent('delivery_failed', (int)$d['client_id'], [
                'numero' => $o['number'] ?? '', 'motif' => mb_strtolower(self::FAIL_REASONS[$reason]), 'creneau' => date('d/m à H:i', strtotime($slotAt)),
            ], $o ? (int)$o['id'] : null);
            AlertService::safe(function () use ($id, $reason, $d, $o): void {
                $num = $o['number'] ?? ('#' . $id);
                if ($reason === 'adresse') {
                    AlertService::raise('delivery_address', 'dlvaddr:' . $id, "Adresse introuvable pour la livraison de $num ({$d['address']}) : joindre le client et corriger.", 'delivery', $id, (int)$d['agency_id']);
                }
                $msg = "Livraison de $num non aboutie (" . mb_strtolower(self::FAIL_REASONS[$reason]) . '), tentative ' . (int)$d['attempts'] . '/' . self::MAX_ATTEMPTS . '.';
                AlertService::raise('delivery_failed', 'dlvfail:' . $id, $msg, 'delivery', $id, (int)$d['agency_id']);
            });
            Audit::log('delivery.failed', 'deliveries', $id, ['reason' => $reason, 'attempts' => (int)$d['attempts']], null, null, $note);
        });
    }

    // ---------------------------------------------------------------- Encaissement du solde

    /** @return array{collected:int,deferred:bool} */
    private function settle(array $d, array $o, array $pay): array
    {
        $balance = (int)$o['on_account'] ? 0 : max(0, (int)$o['total'] - (int)$o['paid']);
        $collected = 0;
        $lines = [];
        foreach ((array)($pay['lines'] ?? []) as $l) {
            $amount = (int)($l['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $method = (string)($l['method'] ?? '');
            if (!in_array($method, self::COURIER_METHODS, true)) {
                throw new \DomainException('Le livreur encaisse en espèces, Orange Money ou MTN MoMo.');
            }
            if ($method !== 'especes' && mb_strlen(trim((string)($l['reference'] ?? ''))) < 6) {
                throw new \DomainException('Référence de la transaction Mobile Money obligatoire (6 caractères minimum).');
            }
            $lines[] = $l;
            $collected += $amount;
        }
        if ($lines) {
            // L'argent perçu entre dans la caisse « Livreur » du jour, à clôturer (sans tolérance) par un responsable
            $session = (new CashService())->courierSession((int)$d['driver_id'], (int)$d['agency_id']);
            (new PaymentService())->recordMixed((int)$o['client_id'], $lines, (int)$o['id'], null, (int)$session['id']);
        }
        $left = $balance - $collected;
        $deferred = false;
        if ($left > 0) {
            if (empty($pay['defer'])) {
                throw new \DomainException('Solde de ' . money($left, true) . ' à encaisser, ou à reporter explicitement.');
            }
            if (mb_strlen(trim((string)($pay['reason'] ?? ''))) < 8) {
                throw new \DomainException('Motif du report obligatoire (8 caractères minimum).');
            }
            $authoriser = $pay['authoriser'] ?? throw new \DomainException('Un report de solde doit être autorisé par un responsable.');
            Audit::log('delivery.defer', 'orders', (int)$o['id'], ['delivery' => (int)$d['id'], 'authorised_by' => (int)$authoriser['id'], 'requested_by' => Auth::id()], ['reste' => $left], ['reste' => $left, 'reporté' => true], (string)$pay['reason']);
            $deferred = true;
        }
        return ['collected' => $collected, 'deferred' => $deferred];
    }

    // ---------------------------------------------------------------- Aides

    private function load(int $id, bool $lock = false): array
    {
        $d = Database::one('SELECT * FROM deliveries WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]) ?? throw new \DomainException('Livraison introuvable.');
        if (Auth::role() !== Role::Livreur && !Auth::canSeeAgency((int)$d['agency_id'])) {
            throw new \DomainException('Livraison introuvable.');
        }
        return $d;
    }

    /** Un livreur n'agit que sur ses propres livraisons ; les autres profils ont besoin du droit de modification. */
    private function actor(array $d): void
    {
        if (Auth::role() === Role::Livreur) {
            if ((int)$d['driver_id'] !== Auth::id()) {
                throw new \DomainException('Cette livraison ne vous est pas affectée.');
            }
            return;
        }
        if (!Auth::can('delivery', 'update')) {
            throw new \DomainException('Action réservée au livreur affecté ou à un responsable.');
        }
    }

    public static function event(int $id, string $status, ?string $note): void
    {
        Database::insert('delivery_events', ['delivery_id' => $id, 'status' => $status, 'note' => $note !== null ? mb_substr($note, 0, 255) : null, 'user_id' => Auth::id() ?: null, 'created_at' => now()]);
    }

    private static function futureSlot(string $slot, bool $required): ?string
    {
        $slot = trim(str_replace('T', ' ', $slot));
        if ($slot === '') {
            if ($required) {
                throw new \DomainException('Créneau obligatoire.');
            }
            return null;
        }
        $ts = strtotime($slot);
        if ($ts === false) {
            throw new \DomainException('Créneau invalide.');
        }
        if ($ts < time() - 300) {
            throw new \DomainException('Le créneau doit être dans le futur.');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /** Enregistre une signature dessinée à l'écran (image PNG en data URL) ; refuse tout autre contenu. */
    public static function saveSignature(string $dataUrl): string
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw new \DomainException('Signature invalide.');
        }
        $bytes = base64_decode($m[1], true);
        if ($bytes === false || strlen($bytes) > 400_000 || strlen($bytes) < 200) {
            throw new \DomainException('Signature invalide ou vide.');
        }
        if ((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/png') {
            throw new \DomainException('La signature doit être une image PNG.');
        }
        $dir = date('Y/m');
        $abs = BASE_PATH . '/public/uploads/' . $dir;
        if (!is_dir($abs) && !mkdir($abs, 0775, true) && !is_dir($abs)) {
            throw new \RuntimeException('Dossier public/uploads non accessible en écriture.');
        }
        $name = 'signature-' . bin2hex(random_bytes(6)) . '.png';
        file_put_contents($abs . '/' . $name, $bytes);
        return '/uploads/' . $dir . '/' . $name;
    }
}
