<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\ClientService;
use App\Services\Numbering;

final class ClientController extends Controller
{
    private const FILTERS = ['' => 'Tous', 'vip' => 'VIP', 'pro' => 'Pros', 'inactifs' => 'Inactifs > 60 j', 'solde' => 'Solde dû'];

    public function index(): void
    {
        $f = array_key_exists($this->str('f'), self::FILTERS) ? $this->str('f') : '';
        $q = $this->str('q');
        $where = ['1 = 1'];
        $having = [];
        $p = [];
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $where[] = '(c.name LIKE :q OR c.code LIKE :q' . (strlen($digits) >= 3 ? ' OR c.phone LIKE :d' : '') . ')';
            $p['q'] = "%$q%";
            if (strlen($digits) >= 3) {
                $p['d'] = "%$digits%";
            }
        }
        match ($f) {
            'vip'      => $where[] = 'c.is_vip = 1',
            'pro'      => $where[] = "c.type = 'pro'",
            'inactifs' => $having[] = '(last_order IS NULL OR last_order < NOW() - INTERVAL 60 DAY)',
            'solde'    => $having[] = 'balance > 0',
            default    => null,
        };
        $clients = Database::all(
            "SELECT c.id, c.code, c.name, c.phone, c.type, c.is_vip, c.loyalty_points,
                    COUNT(o.id) orders_count, MAX(o.created_at) last_order, COALESCE(SUM(o.total), 0) revenue,
                    COALESCE(SUM(CASE WHEN o.on_account = 0 AND o.status IN ('en_atelier', 'pret') THEN o.total - o.paid ELSE 0 END), 0) balance
             FROM clients c LEFT JOIN orders o ON o.client_id = c.id AND o.status <> 'annule'
             WHERE " . implode(' AND ', $where) . '
             GROUP BY c.id, c.code, c.name, c.phone, c.type, c.is_vip, c.loyalty_points' .
            ($having ? ' HAVING ' . implode(' AND ', $having) : '') . '
             ORDER BY last_order IS NULL, last_order DESC LIMIT 150',
            $p
        );
        $this->view('clients/index', [
            'title'   => 'Clients',
            'clients' => $clients,
            'filters' => self::FILTERS,
            'f'       => $f,
            'q'       => $q,
            'totals'  => Database::one("SELECT COUNT(*) n, SUM(type = 'pro') pros, SUM(is_vip = 1) vip FROM clients"),
        ]);
    }

    public function show(string $id): void
    {
        $c = $this->find((int)$id);
        $stats = Database::one("SELECT COUNT(*) n, COALESCE(SUM(total), 0) revenue, MIN(created_at) first, MAX(created_at) last FROM orders WHERE client_id = ? AND status <> 'annule'", [$c['id']]);
        $freq = (int)$stats['n'] > 1 ? (int)round((strtotime($stats['last']) - strtotime($stats['first'])) / 86400 / ((int)$stats['n'] - 1)) : null;
        $this->view('clients/show', [
            'title'       => $c['name'],
            'c'           => $c,
            'stats'       => $stats,
            'freq'        => $freq,
            'outstanding' => $c['type'] === 'pro' ? ClientService::outstanding((int)$c['id']) : null,
            'orders'      => Database::all('SELECT o.*, (SELECT COUNT(*) FROM garments g WHERE g.order_id = o.id) pcs FROM orders o WHERE o.client_id = ? ORDER BY o.created_at DESC LIMIT 15', [$c['id']]),
            'contract'    => Database::one('SELECT * FROM contracts WHERE client_id = ? AND active = 1 ORDER BY id DESC LIMIT 1', [$c['id']]),
            'complaints'  => Database::all('SELECT * FROM complaints WHERE client_id = ? ORDER BY created_at DESC LIMIT 5', [$c['id']]),
            'messages'    => Database::all('SELECT * FROM messages WHERE client_id = ? ORDER BY created_at DESC LIMIT 8', [$c['id']]),
            'referrer'    => $c['referred_by'] ? Database::one('SELECT id, name FROM clients WHERE id = ?', [$c['referred_by']]) : null,
        ]);
    }

    public function create(): void
    {
        $this->view('clients/form', ['title' => 'Nouveau client', 'c' => null, 'back' => $this->str('retour')]);
    }

    public function edit(string $id): void
    {
        $c = $this->find((int)$id);
        $this->view('clients/form', ['title' => 'Modifier ' . $c['name'], 'c' => $c, 'back' => '']);
    }

    public function store(): void
    {
        $data = $this->validated();
        $dup = Database::one('SELECT id, name FROM clients WHERE phone = ?', [$data['phone']]);
        if ($dup) {
            $this->fail("Ce numéro appartient déjà à {$dup['name']} (fiche n° {$dup['id']}).");
        }
        if ($ref = $this->str('referrer_phone')) {
            $data['referred_by'] = Database::value('SELECT id FROM clients WHERE phone = ?', [ClientService::normalizePhone($ref)]) ?: null;
        }
        $data['code'] = Numbering::next('client', 'CL-%2$06d');
        $data['created_at'] = now();
        $id = Database::insert('clients', $data);
        Audit::log('client.create', 'clients', $id);
        if ($this->str('retour') === 'commande') {
            $this->ok('Client créé.', '/commandes/nouvelle?client=' . $id);
        }
        $this->ok('Client créé.', '/clients/' . $id);
    }

    public function update(string $id): void
    {
        $c = $this->find((int)$id);
        $data = $this->validated();
        $dup = Database::one('SELECT id, name FROM clients WHERE phone = ? AND id <> ?', [$data['phone'], $c['id']]);
        if ($dup) {
            $this->fail("Ce numéro appartient déjà à {$dup['name']}.");
        }
        $changed = array_keys(array_filter($data, fn($v, $k) => array_key_exists($k, $c) && (string)$c[$k] !== (string)$v, ARRAY_FILTER_USE_BOTH));
        Database::update('clients', $data, 'id = :id', ['id' => $c['id']]);
        Audit::log('client.update', 'clients', (int)$c['id'], [], array_intersect_key($c, array_flip($changed)), array_intersect_key($data, array_flip($changed)));
        $this->ok('Fiche client mise à jour.', '/clients/' . $c['id']);
    }

    /** Recherche rapide (réception de commande) */
    public function lookup(): void
    {
        $q = $this->str('q');
        if (mb_strlen($q) < 2) {
            $this->json([]);
        }
        $digits = preg_replace('/\D/', '', $q) ?? '';
        $rows = Database::all(
            'SELECT id, code, name, phone, is_vip, type, preferences FROM clients WHERE name LIKE :q OR code LIKE :q' . (strlen($digits) >= 3 ? ' OR phone LIKE :d' : '') . ' ORDER BY name LIMIT 8',
            ['q' => "%$q%"] + (strlen($digits) >= 3 ? ['d' => "%$digits%"] : [])
        );
        foreach ($rows as &$r) {
            $r['phone_fmt'] = ClientService::formatPhone($r['phone']);
            $r['balance'] = (int)Database::value("SELECT COALESCE(SUM(total - paid), 0) FROM orders WHERE client_id = ? AND on_account = 0 AND status IN ('en_atelier', 'pret')", [$r['id']]);
            $r['orders'] = (int)Database::value("SELECT COUNT(*) FROM orders WHERE client_id = ? AND status <> 'annule'", [$r['id']]);
        }
        $this->json($rows);
    }

    private function find(int $id): array
    {
        return Database::one('SELECT * FROM clients WHERE id = ?', [$id]) ?? throw new HttpException(404, 'Client introuvable');
    }

    private function validated(): array
    {
        $req = $this->required(['name' => 'Nom', 'phone' => 'Téléphone']);
        $phone = ClientService::normalizePhone($req['phone']);
        if (strlen(preg_replace('/\D/', '', $phone) ?? '') < 8) {
            $this->fail('Numéro de téléphone invalide.');
        }
        $email = $this->str('email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('Adresse e-mail invalide.');
        }
        $type = $this->str('type') === 'pro' ? 'pro' : 'particulier';
        $channel = in_array($this->str('preferred_channel'), ['sms', 'whatsapp', 'email'], true) ? $this->str('preferred_channel') : 'sms';
        return [
            'type'               => $type,
            'name'               => mb_substr($req['name'], 0, 150),
            'phone'              => $phone,
            'email'              => $email ?: null,
            'address'            => $this->str('address') ?: null,
            'is_vip'             => input('is_vip') ? 1 : 0,
            'credit_limit'       => $type === 'pro' ? max(0, $this->int('credit_limit')) : 0,
            'payment_terms_days' => $type === 'pro' ? max(0, $this->int('payment_terms_days', 30)) : 0,
            'preferred_channel'  => $channel,
            'preferences'        => $this->str('preferences') ?: null,
            'notes'              => $this->str('notes') ?: null,
        ];
    }
}
