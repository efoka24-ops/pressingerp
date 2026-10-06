<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Domain\Role;
use App\Services\Authorizer;
use App\Services\DeliveryService;

final class DeliveryController extends Controller
{
    private const TABS = [
        'a_affecter' => 'À affecter',
        'en_cours'   => 'En cours',
        'terminees'  => 'Terminées du jour',
    ];

    private function isDriver(): bool
    {
        return Auth::role() === Role::Livreur;
    }

    public function index(): void
    {
        $driver = $this->isDriver();
        $tab = $driver ? 'en_cours' : (array_key_exists($this->str('onglet'), self::TABS) ? $this->str('onglet') : 'a_affecter');
        $where = ['1 = 1'];
        $p = [];
        if ($driver) {
            $where[] = 'd.driver_id = ?';
            $p[] = Auth::id();
        } elseif ($s = Auth::scopedAgencyId()) {
            $where[] = 'd.agency_id = ' . (int)$s;
        }
        $conds = [
            'a_affecter' => "d.driver_id IS NULL AND d.status IN ('a_collecter', 'a_livrer', 'non_livre', 'en_traitement')",
            'en_cours'   => "d.driver_id IS NOT NULL AND d.status IN ('a_collecter', 'collecte', 'en_traitement', 'a_livrer', 'en_route', 'non_livre')",
            'terminees'  => "d.status = 'livre' AND d.delivered_at >= CURDATE()",
        ];
        $list = fn(string $t) => Database::all(
            "SELECT d.*, o.number, o.total, o.paid, o.on_account, c.name client, u.name driver
             FROM deliveries d JOIN clients c ON c.id = d.client_id LEFT JOIN orders o ON o.id = d.order_id LEFT JOIN users u ON u.id = d.driver_id
             WHERE " . implode(' AND ', $where) . ' AND ' . $conds[$t] . ' ORDER BY (d.slot_at IS NULL), d.slot_at, d.id LIMIT 200',
            $p
        );
        $counts = [];
        foreach (array_keys(self::TABS) as $t) {
            $counts[$t] = (int)Database::value('SELECT COUNT(*) FROM deliveries d WHERE ' . implode(' AND ', $where) . ' AND ' . $conds[$t], $p);
        }
        $this->view('delivery/index', [
            'title'  => $driver ? 'Ma tournée' : 'Livraisons & collectes',
            'rows'   => $list($tab),
            'tab'    => $tab,
            'tabs'   => self::TABS,
            'counts' => $counts,
            'driver' => $driver,
            'drivers' => $driver ? [] : $this->drivers(),
        ]);
    }

    public function show(string $id): void
    {
        $d = $this->find((int)$id);
        $order = $d['order_id'] ? Database::one('SELECT * FROM orders WHERE id = ?', [$d['order_id']]) : null;
        $balance = $order && !(int)$order['on_account'] ? max(0, (int)$order['total'] - (int)$order['paid']) : 0;
        $this->view('delivery/show', [
            'title'    => ($d['kind'] === 'collect' ? 'Collecte' : 'Livraison') . ' · ' . ($order['number'] ?? '#' . $d['id']),
            'd'        => $d,
            'order'    => $order,
            'client'   => Database::one('SELECT id, code, name, phone FROM clients WHERE id = ?', [$d['client_id']]),
            'balance'  => $balance,
            'events'   => Database::all('SELECT e.*, u.name user FROM delivery_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.delivery_id = ? ORDER BY e.id', [$d['id']]),
            'proofs'   => Database::all('SELECT * FROM delivery_proofs WHERE delivery_id = ? ORDER BY id', [$d['id']]),
            'drivers'  => $this->isDriver() ? [] : $this->drivers(),
            'driver'   => $this->isDriver(),
            'garments' => $order ? Database::all('SELECT label, qty FROM garments WHERE order_id = ? ORDER BY seq', [$order['id']]) : [],
        ]);
    }

    /** Bon de livraison imprimable : pièces remises, adresse, solde à percevoir, cases de signature. */
    public function note(string $id): void
    {
        $d = $this->find((int)$id);
        $order = $d['order_id'] ? Database::one('SELECT * FROM orders WHERE id = ?', [$d['order_id']]) : null;
        $this->view('delivery/note', [
            'title'    => 'Bon de livraison',
            'd'        => $d,
            'order'    => $order,
            'client'   => Database::one('SELECT name FROM clients WHERE id = ?', [$d['client_id']]),
            'agency'   => (string)Database::value('SELECT name FROM agencies WHERE id = ?', [$d['agency_id']]),
            'driver'   => $d['driver_id'] ? (string)Database::value('SELECT name FROM users WHERE id = ?', [$d['driver_id']]) : '',
            'garments' => $order ? Database::all('SELECT label, code, qty FROM garments WHERE order_id = ? ORDER BY seq', [$order['id']]) : [],
            'balance'  => $order && !(int)$order['on_account'] ? max(0, (int)$order['total'] - (int)$order['paid']) : 0,
        ]);
    }

    public function newCollect(): void
    {
        $client = ($id = $this->int('client')) ? Database::one('SELECT id, code, name, phone FROM clients WHERE id = ?', [$id]) : null;
        $this->view('delivery/collect', ['title' => 'Demande de collecte', 'client' => $client, 'clients' => $client ? [] : Database::all('SELECT id, code, name, phone FROM clients ORDER BY id DESC LIMIT 30')]);
    }

    public function createCollect(): void
    {
        try {
            $id = (new DeliveryService())->createCollect($this->int('client_id'), $this->str('address'), $this->str('slot'), $this->str('notes'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Collecte enregistrée : affectez un livreur.', '/livraisons/' . $id);
    }

    public function assign(string $id): void
    {
        $this->run(fn() => (new DeliveryService())->assign((int)$id, $this->int('driver_id'), $this->str('slot')), 'Livreur affecté.', (int)$id);
    }

    public function address(string $id): void
    {
        $this->run(fn() => (new DeliveryService())->updateAddress((int)$id, $this->str('address'), $this->str('reason')), 'Adresse corrigée.', (int)$id);
    }

    public function collected(string $id): void
    {
        $this->run(fn() => (new DeliveryService())->markCollected((int)$id), 'Collecte enregistrée. À la réception : créer la commande.', (int)$id);
    }

    public function start(string $id): void
    {
        $this->run(fn() => (new DeliveryService())->start((int)$id), 'Départ enregistré. Le client a reçu son code de remise.', (int)$id);
    }

    public function complete(string $id): void
    {
        $this->run(function () use ($id): void {
            $pay = ['lines' => (array)($_POST['lines'] ?? [])];
            if ($this->str('defer') === '1') {
                $d = $this->find((int)$id);
                $pay += ['defer' => true, 'reason' => $this->str('defer_reason'), 'authoriser' => Authorizer::fromRequest((int)$d['agency_id'])];
            }
            (new DeliveryService())->complete((int)$id, [
                'code'      => $this->str('code'),
                'signature' => $this->str('signature'),
                'photo'     => !empty($_FILES['photo']['tmp_name']) ? $_FILES['photo'] : null,
            ], $pay);
        }, 'Livraison clôturée.', (int)$id);
    }

    public function failed(string $id): void
    {
        $this->run(fn() => (new DeliveryService())->fail((int)$id, $this->str('reason'), $this->str('note'), $this->str('slot')), 'Échec enregistré : le client est prévenu, nouveau créneau fixé.', (int)$id);
    }

    private function run(callable $fn, string $message, int $id): never
    {
        try {
            $fn();
        } catch (\DomainException $e) {
            $this->fail($e->getMessage(), '/livraisons/' . $id);
        }
        $this->ok($message, '/livraisons/' . $id);
    }

    /** Une livraison n'est visible que de son livreur, ou du personnel de son agence. */
    private function find(int $id): array
    {
        $d = Database::one('SELECT * FROM deliveries WHERE id = ?', [$id]);
        if (!$d || ($this->isDriver() ? (int)$d['driver_id'] !== Auth::id() : !Auth::canSeeAgency((int)$d['agency_id']))) {
            throw new HttpException(404, 'Livraison introuvable.');
        }
        return $d;
    }

    private function drivers(): array
    {
        $scope = Auth::scopedAgencyId();
        return Database::all("SELECT id, name FROM users WHERE role = 'livreur' AND active = 1" . ($scope ? ' AND agency_id = ' . $scope : '') . ' ORDER BY name');
    }
}
