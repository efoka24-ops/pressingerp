<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Domain\ServiceLevel;

/**
 * Réception hors-ligne.
 *  - Un poste (tablette ou ordinateur) est autorisé par un responsable et reçoit un jeton.
 *  - Il reçoit des plages de numéros de commande réservées : les numéros restent uniques et sans collision
 *    même sans réseau. Un numéro réservé mais jamais utilisé reste tracé, il n'est jamais réattribué.
 *  - Les commandes saisies sans réseau sont synchronisées une à une, de façon idempotente.
 *  - Les prix sont recalculés par le serveur d'après l'historique des tarifs à l'heure de la saisie.
 */
final class OfflineService
{
    /** Quand il reste moins de numéros que cela, une nouvelle plage est délivrée. */
    public const LOW_WATER = 15;

    /** @return array{id:int,token:string} le jeton n'est montré qu'une fois */
    public function register(int $agencyId, string $label): array
    {
        $label = trim($label);
        if ($label === '') {
            throw new \DomainException('Donnez un nom au poste (ex. « Comptoir Akwa — tablette 1 »).');
        }
        if (!Database::value('SELECT id FROM agencies WHERE id = ? AND is_workshop = 0', [$agencyId])) {
            throw new \DomainException('Agence invalide.');
        }
        $token = bin2hex(random_bytes(24));
        $id = Database::insert('workstations', [
            'agency_id' => $agencyId, 'label' => mb_substr($label, 0, 80), 'token_hash' => hash('sha256', $token),
            'active' => 1, 'registered_by' => Auth::id() ?: null, 'created_at' => now(),
        ]);
        Audit::log('workstation.register', 'workstations', $id, ['label' => $label, 'agency' => $agencyId]);
        return ['id' => $id, 'token' => $token];
    }

    /** Poste correspondant au jeton, s'il est actif et de l'agence de l'utilisateur. */
    public function authenticate(string $token): array
    {
        $ws = $token !== '' ? Database::one('SELECT * FROM workstations WHERE token_hash = ? AND active = 1', [hash('sha256', $token)]) : null;
        if (!$ws) {
            throw new \DomainException('Poste non autorisé pour la réception hors-ligne. Demandez à un responsable de l\'autoriser.');
        }
        if (!Auth::canSeeAgency((int)$ws['agency_id'])) {
            throw new \DomainException('Ce poste appartient à une autre agence.');
        }
        Database::update('workstations', ['last_seen_at' => now()], 'id = :id', ['id' => $ws['id']]);
        return $ws;
    }

    public function deactivate(int $id, string $reason): void
    {
        $ws = Database::one('SELECT * FROM workstations WHERE id = ?', [$id]) ?? throw new \DomainException('Poste introuvable.');
        if (trim($reason) === '') {
            throw new \DomainException('Motif obligatoire.');
        }
        Database::transaction(function () use ($ws, $reason): void {
            Database::update('workstations', ['active' => 0], 'id = :id', ['id' => $ws['id']]);
            Database::run('UPDATE number_blocks SET closed_at = ? WHERE workstation_id = ? AND closed_at IS NULL', [now(), $ws['id']]);
            Audit::log('workstation.deactivate', 'workstations', (int)$ws['id'], ['label' => $ws['label']], ['active' => 1], ['active' => 0], $reason);
        });
    }

    /** Numéros réservés à ce poste et pas encore synchronisés. */
    public function unused(array $ws): int
    {
        $issued = (int)Database::value('SELECT COALESCE(SUM(to_seq - from_seq + 1), 0) FROM number_blocks WHERE workstation_id = ? AND closed_at IS NULL', [$ws['id']]);
        $used = (int)Database::value('SELECT COUNT(*) FROM offline_syncs WHERE workstation_id = ?', [$ws['id']]);
        return max(0, $issued - $used);
    }

    /** Délivre une plage de numéros si le poste est presque à court. @return ?array{year:int,from:int,to:int} */
    public function allocateBlockIfNeeded(array $ws): ?array
    {
        if ($this->unused($ws) >= self::LOW_WATER) {
            return null;
        }
        $size = max(10, (int)SettingsService::get('offline.block_size'));
        $year = (int)date('Y');
        return Database::transaction(function () use ($ws, $size, $year): array {
            Database::run('INSERT INTO counters (name, year, value) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE value = value', ['order', $year]);
            $value = (int)Database::value('SELECT value FROM counters WHERE name = ? AND year = ? FOR UPDATE', ['order', $year]);
            $from = $value + 1;
            $to = $value + $size;
            Database::run('UPDATE counters SET value = ? WHERE name = ? AND year = ?', [$to, 'order', $year]);
            Database::insert('number_blocks', ['workstation_id' => $ws['id'], 'year' => $year, 'from_seq' => $from, 'to_seq' => $to, 'issued_at' => now()]);
            Audit::log('offline.block', 'workstations', (int)$ws['id'], ['year' => $year, 'from' => $from, 'to' => $to]);
            return ['year' => $year, 'from' => $from, 'to' => $to];
        });
    }

    /**
     * Données nécessaires pour saisir des commandes sans réseau.
     * Volontairement limitées : clients récents de l'agence, tarifs résolus, paramètres de calcul.
     */
    public function snapshot(array $ws): array
    {
        $agencyId = (int)$ws['agency_id'];
        PricingService::resetCache();
        $pricing = new PricingService();
        $articles = Database::all('SELECT id, name, unit, fragile FROM articles WHERE active = 1 ORDER BY sort, name');
        $normal = [];
        $vip = [];
        foreach ($articles as $a) {
            if ($p = $pricing->price((int)$a['id'], 0, $agencyId)) {
                $normal[(int)$a['id']] = $p['price'];
            }
            if ($p = $pricing->price((int)$a['id'], 0, $agencyId, null, null, true)) {
                $vip[(int)$a['id']] = $p['price'];
            }
        }

        $rows = Database::all(
            'SELECT c.id, c.code, c.name, c.phone, c.type, c.is_vip, c.preferences FROM clients c
             WHERE c.id IN (SELECT DISTINCT client_id FROM orders WHERE agency_id = ? AND created_at > NOW() - INTERVAL 120 DAY)
                OR c.created_at > NOW() - INTERVAL 30 DAY
             ORDER BY c.id DESC LIMIT 500',
            [$agencyId]
        );
        usort($rows, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $counts = $contracts = $business = [];
        if ($ids) {
            $in = implode(',', $ids);
            foreach (Database::all("SELECT client_id, COUNT(*) n FROM orders WHERE client_id IN ($in) AND status <> 'annule' GROUP BY client_id") as $r) {
                $counts[(int)$r['client_id']] = (int)$r['n'];
            }
            foreach (Database::all("SELECT DISTINCT client_id FROM contracts WHERE client_id IN ($in) AND active = 1 AND CURDATE() BETWEEN start_date AND end_date") as $r) {
                $contracts[(int)$r['client_id']] = true;
            }
            foreach (Database::all("SELECT DISTINCT client_id FROM price_lists WHERE kind = 'business' AND active = 1 AND client_id IN ($in)") as $r) {
                $business[(int)$r['client_id']] = true;
            }
        }
        $nth = (int)Config::get('loyalty.every_nth_order', 0);
        $clients = [];
        $businessPrices = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $blocked = null;
            if ($r['type'] === 'pro' && isset($contracts[$id])) {
                $blocked = 'Client en compte : réception hors-ligne impossible';
            } elseif ($err = ClientService::identifiableError($r)) {
                $blocked = 'Fiche incomplète : ' . $err;
            }
            $clients[] = [
                'id' => $id, 'code' => $r['code'], 'name' => $r['name'], 'phone' => $r['phone'], 'phone_fmt' => ClientService::formatPhone($r['phone']),
                'type' => $r['type'], 'is_vip' => (int)$r['is_vip'], 'preferences' => $r['preferences'],
                'next_nth' => $nth > 0 && (($counts[$id] ?? 0) + 1) % $nth === 0, 'blocked' => $blocked,
            ];
            if ($blocked === null && isset($business[$id])) {
                foreach ($articles as $a) {
                    $p = $pricing->price((int)$a['id'], $id, $agencyId);
                    if ($p && $p['kind'] === 'business') {
                        $businessPrices[$id][(int)$a['id']] = $p['price'];
                    }
                }
            }
        }

        $levels = [];
        foreach (ServiceLevel::cases() as $l) {
            $levels[] = ['value' => $l->value, 'label' => $l->label(), 'surcharge_pct' => $l->surchargePct(), 'delay_hours' => $l->delayHours()];
        }
        return [
            'snapshot_at' => date('Y-m-d H:i:s'),
            'server_time' => time(),
            'agency'      => ['id' => $agencyId, 'name' => (string)Database::value('SELECT name FROM agencies WHERE id = ?', [$agencyId])],
            'user'        => ['id' => Auth::id(), 'name' => (string)(Auth::user()['name'] ?? '')],
            'articles'    => array_map(fn($a) => ['id' => (int)$a['id'], 'name' => $a['name'], 'unit' => $a['unit'], 'fragile' => (int)$a['fragile']], $articles),
            'treatments'  => Database::all('SELECT id, label FROM treatments WHERE active = 1 ORDER BY sort, id'),
            'prices'      => ['normal' => $normal, 'vip' => $vip, 'business' => $businessPrices],
            'clients'     => $clients,
            'levels'      => $levels,
            'settings'    => [
                'vat_rate'        => (string)SettingsService::get('tax.vat_rate'),
                'photo_threshold' => (int)SettingsService::get('photo.value_threshold'),
                'delivery_fee'    => (int)Config::get('delivery_fee', 0),
                'nth_pct'         => (int)Config::get('loyalty.nth_discount_pct', 10),
                'company'         => (string)SettingsService::get('company.name'),
                'niu'             => (string)SettingsService::get('company.niu'),
                'app_url'         => rtrim((string)Config::get('app.url'), '/'),
            ],
        ];
    }

    /**
     * Synchronise une commande saisie hors-ligne. Idempotent : un numéro déjà synchronisé renvoie la même réponse.
     * @return array{status:string,number:string,order_id:?int,message:?string}
     */
    public function syncOrder(array $ws, array $p, array $photos = []): array
    {
        $number = (string)($p['number'] ?? '');
        if (!preg_match('/^PR-(\d{4})-(\d{6})$/', $number, $m)) {
            return ['status' => 'rejected', 'number' => $number, 'order_id' => null, 'message' => 'Numéro de commande invalide.'];
        }
        $year = (int)$m[1];
        $seq = (int)$m[2];

        $done = Database::one('SELECT * FROM offline_syncs WHERE number = ?', [$number]);
        if ($done && (int)$done['workstation_id'] !== (int)$ws['id']) {
            return ['status' => 'rejected', 'number' => $number, 'order_id' => null, 'message' => 'Ce numéro appartient à un autre poste.'];
        }
        if ($done && $done['status'] === 'ok') {
            return ['status' => 'duplicate', 'number' => $number, 'order_id' => (int)$done['order_id'], 'message' => null];
        }
        $inBlock = Database::value('SELECT id FROM number_blocks WHERE workstation_id = ? AND year = ? AND ? BETWEEN from_seq AND to_seq', [$ws['id'], $year, $seq]);
        if (!$inBlock) {
            return $this->record($ws, $number, 'rejected', null, 'Numéro hors des plages réservées à ce poste.');
        }

        try {
            $client = $this->resolveClient((array)($p['client'] ?? []));
            $now = time();
            $createdTs = isset($p['created_at']) ? (int)strtotime((string)$p['created_at']) : $now;
            $createdTs = ($createdTs <= 0 || $createdTs > $now + 300) ? $now : max($createdTs, $now - 30 * 86400);
            $createdAt = date('Y-m-d H:i:s', $createdTs);
            // Les prix sont ceux en vigueur à l'heure du cache du poste (au plus 14 jours avant la saisie)
            $snapTs = isset($p['snapshot_at']) ? (int)strtotime((string)$p['snapshot_at']) : $createdTs;
            $asOf = date('Y-m-d H:i:s', min($createdTs, max($snapTs ?: $createdTs, $createdTs - 14 * 86400)));

            $lines = [];
            foreach ((array)($p['lines'] ?? []) as $i => $l) {
                if (is_array($l)) {
                    $lines[(int)$i] = array_intersect_key($l, array_flip(['article_id', 'qty', 'treatment_id', 'brand', 'color', 'material', 'damages']));
                }
            }
            $token = (string)($p['tracking_token'] ?? '');
            $opts = [
                'number' => $number, 'created_at' => $createdAt, 'as_of' => $asOf, 'offline' => true, 'workstation_id' => (int)$ws['id'],
                'agency_id' => (int)$ws['agency_id'], 'user_id' => $this->creator($p, (int)$ws['agency_id']),
            ];
            if (preg_match('/^[0-9a-f]{32}$/', $token) && !Database::value('SELECT id FROM orders WHERE tracking_token = ?', [$token])) {
                $opts['tracking_token'] = $token;
            }
            $in = [
                'client_id' => (int)$client['id'], 'service_level' => (string)($p['service_level'] ?? 'standard'), 'lines' => $lines,
                'delivery' => !empty($p['delivery']), 'delivery_address' => (string)($p['delivery_address'] ?? ''), 'notes' => (string)($p['notes'] ?? ''),
            ];
            $orderId = (new OrderService())->create($in, $photos, $opts);

            $total = (int)Database::value('SELECT total FROM orders WHERE id = ?', [$orderId]);
            $printed = isset($p['total']) ? (int)$p['total'] : $total;
            if ($printed !== $total) {
                // Le prix du ticket remis au client diffère de celui du serveur : on conserve le prix du serveur et on le signale
                Audit::log('offline.total_mismatch', 'orders', $orderId, ['number' => $number], $printed, $total, 'Écart entre le ticket hors-ligne et le tarif du serveur');
                Database::run('UPDATE orders SET notes = ? WHERE id = ?', [mb_substr(trim((string)Database::value('SELECT notes FROM orders WHERE id = ?', [$orderId]) . "\nÉcart de prix hors-ligne : ticket $printed FCFA, serveur $total FCFA."), 0, 60000), $orderId]);
            }
            if (!empty($p['labels_printed'])) {
                Database::insert('label_prints', ['order_id' => $orderId, 'kind' => 'print', 'reason' => 'Imprimées hors-ligne', 'user_id' => Auth::id() ?: null, 'created_at' => now()]);
            }
            Database::update('workstations', ['last_sync_at' => now()], 'id = :id', ['id' => $ws['id']]);
            return $this->record($ws, $number, 'ok', $orderId, null);
        } catch (\DomainException $e) {
            return $this->record($ws, $number, 'rejected', null, mb_substr($e->getMessage(), 0, 250));
        }
    }

    /** Client existant (par identifiant, vérifié identifiable) ou nouveau client (retrouvé par son numéro, sinon créé). */
    private function resolveClient(array $c): array
    {
        if (!empty($c['id'])) {
            $client = Database::one('SELECT * FROM clients WHERE id = ?', [(int)$c['id']]) ?? throw new \DomainException('Client introuvable.');
            return $client;
        }
        return ClientService::findOrCreate((string)($c['name'] ?? ''), (string)($c['phone'] ?? ''), (string)($c['type'] ?? 'particulier'))['client'];
    }

    /** Auteur de la saisie : l'utilisateur du poste s'il existe dans cette agence, sinon l'utilisateur qui synchronise. */
    private function creator(array $p, int $agencyId): int
    {
        $uid = (int)($p['user_id'] ?? 0);
        if ($uid && Database::value('SELECT id FROM users WHERE id = ? AND active = 1 AND (agency_id = ? OR role IN (\'admin\', \'direction\'))', [$uid, $agencyId])) {
            return $uid;
        }
        return Auth::id();
    }

    private function record(array $ws, string $number, string $status, ?int $orderId, ?string $message): array
    {
        Database::run(
            'INSERT INTO offline_syncs (workstation_id, number, status, order_id, message, received_by, received_at) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), order_id = VALUES(order_id), message = VALUES(message), received_by = VALUES(received_by), received_at = VALUES(received_at)',
            [$ws['id'], $number, $status, $orderId, $message, Auth::id() ?: null, now()]
        );
        if ($status === 'rejected') {
            Audit::log('offline.rejected', 'workstations', (int)$ws['id'], ['number' => $number], null, null, $message);
        }
        return ['status' => $status === 'ok' ? 'created' : 'rejected', 'number' => $number, 'order_id' => $orderId, 'message' => $message];
    }

    /** @return list<array> postes avec leurs plages : émis, utilisés, jamais utilisés */
    public static function stations(): array
    {
        $rows = Database::all(
            'SELECT w.*, a.name agency,
                    (SELECT COALESCE(SUM(to_seq - from_seq + 1), 0) FROM number_blocks b WHERE b.workstation_id = w.id) issued,
                    (SELECT COUNT(*) FROM offline_syncs s WHERE s.workstation_id = w.id AND s.status = \'ok\') synced,
                    (SELECT COUNT(*) FROM offline_syncs s WHERE s.workstation_id = w.id AND s.status = \'rejected\') rejected
             FROM workstations w JOIN agencies a ON a.id = w.agency_id ORDER BY w.active DESC, w.id'
        );
        foreach ($rows as &$r) {
            $r['unused'] = max(0, (int)$r['issued'] - (int)$r['synced'] - (int)$r['rejected']);
        }
        return $rows;
    }
}
