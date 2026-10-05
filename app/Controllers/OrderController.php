<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Domain\PaymentMethod;
use App\Domain\ServiceLevel;
use App\Services\Audit;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\Uploads;

final class OrderController extends Controller
{
    private const TABS = [
        'actives'      => 'En cours',
        'atelier'      => 'En atelier',
        'pretes'       => 'Prêtes',
        'retard'       => 'En retard',
        'non_retirees' => 'Non retirées',
        'livraisons'   => 'Livraisons',
        'historique'   => 'Historique',
    ];

    private function tabCondition(string $tab): string
    {
        $days = (int)\App\Core\Config::get('uncollected_days', 3);
        return match ($tab) {
            'atelier'      => "o.status = 'en_atelier'",
            'pretes'       => "o.status = 'pret'",
            'retard'       => "o.status = 'en_atelier' AND o.promised_at < NOW()",
            'non_retirees' => "o.status = 'pret' AND o.ready_at < NOW() - INTERVAL $days DAY",
            'livraisons'   => "o.delivery_address IS NOT NULL AND o.status IN ('en_atelier', 'pret')",
            'historique'   => "o.status IN ('retire', 'livre', 'annule')",
            default        => "o.status IN ('en_atelier', 'pret')",
        };
    }

    public function index(): void
    {
        $tab = array_key_exists($this->str('tab'), self::TABS) ? $this->str('tab') : 'actives';
        $ag = Auth::scopedAgencyId() ?: $this->int('agence');
        $q = $this->str('q');
        $where = [$this->tabCondition($tab)];
        $p = [];
        if ($ag) {
            $where[] = 'o.agency_id = :ag';
            $p['ag'] = $ag;
        }
        if ($q !== '') {
            $where[] = '(o.number LIKE :q OR c.name LIKE :q OR c.phone LIKE :q)';
            $p['q'] = "%$q%";
        }
        $orders = Database::all(
            "SELECT o.*, c.name client, c.is_vip, c.type client_type, a.name agency, COUNT(g.id) pcs, SUM(g.step IN ('pret', 'retire')) done
             FROM orders o JOIN clients c ON c.id = o.client_id JOIN agencies a ON a.id = o.agency_id LEFT JOIN garments g ON g.order_id = o.id
             WHERE " . implode(' AND ', $where) . '
             GROUP BY o.id ORDER BY ' . ($tab === 'historique' ? 'o.created_at DESC' : 'o.promised_at ASC') . ' LIMIT 200',
            $p
        );
        $counts = [];
        foreach (array_keys(self::TABS) as $t) {
            if ($t !== 'historique') {
                $counts[$t] = (int)Database::value('SELECT COUNT(*) FROM orders o WHERE ' . $this->tabCondition($t) . ($ag ? ' AND o.agency_id = ' . $ag : ''));
            }
        }
        $this->view('orders/index', [
            'title'    => 'Commandes',
            'orders'   => $orders,
            'tab'      => $tab,
            'tabs'     => self::TABS,
            'counts'   => $counts,
            'q'        => $q,
            'ag'       => $ag,
            'agencies' => Database::all('SELECT id, name FROM agencies WHERE is_workshop = 0 ORDER BY id'),
        ]);
    }

    public function create(): void
    {
        $client = null;
        if ($id = $this->int('client')) {
            $client = Database::one('SELECT id, code, name, phone, is_vip, type, preferences FROM clients WHERE id = ?', [$id]);
        }
        $this->view('orders/create', [
            'title'    => 'Nouvelle commande',
            'client'   => $client,
            'articles' => Database::all('SELECT id, name, price, unit, fragile FROM articles WHERE active = 1 ORDER BY sort, name'),
            'levels'   => ServiceLevel::cases(),
            'methods'  => PaymentMethod::counter(),
            'treatments' => Database::all('SELECT id, code, label, steps FROM treatments WHERE active = 1 ORDER BY sort, id'),
        ]);
    }

    public function store(): void
    {
        try {
            $id = (new OrderService())->create($_POST, Uploads::normalize($_FILES['photos'] ?? []));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $deposit = $this->int('deposit');
        if ($deposit > 0) {
            try {
                $clientId = (int)Database::value('SELECT client_id FROM orders WHERE id = ?', [$id]);
                $method = PaymentMethod::tryFrom($this->str('deposit_method')) ?? PaymentMethod::Especes;
                (new PaymentService())->record($clientId, $method, $deposit, orderId: $id, reference: $this->str('deposit_ref') ?: null);
            } catch (\DomainException $e) {
                flash('warn', 'Commande enregistrée, mais acompte non encaissé : ' . $e->getMessage());
                redirect("/commandes/$id?etiquettes=1");
            }
        }
        $this->ok('Commande enregistrée.' . ($deposit > 0 ? ' Acompte encaissé.' : ''), "/commandes/$id?etiquettes=1");
    }

    /** Devis en direct pour l'écran de réception (JSON) */
    public function quote(): void
    {
        try {
            $level = ServiceLevel::tryFrom($this->str('service_level')) ?? ServiceLevel::Standard;
            $lines = array_filter((array)($_POST['lines'] ?? []), fn($l) => is_array($l) && !empty($l['article_id']));
            $q = (new PricingService())->quote($this->int('client_id'), $level, $lines, !empty($_POST['delivery']));
            $this->json([
                'ok'             => true,
                'count'          => count($q['lines']),
                'subtotal'       => money($q['subtotal']),
                'surcharge'      => money($q['surcharge']),
                'surcharge_pct'  => $level->surchargePct(),
                'discount'       => $q['discount'] ? '−' . money($q['discount']) : '',
                'discount_label' => $q['discount_label'],
                'delivery_fee'   => $q['delivery_fee'] ? money($q['delivery_fee']) : '',
                'total'          => money($q['total']),
                'promised'       => fdate($level->promisedAt()),
                'photo_lines'    => (object)$q['photo_lines'],
                'tariffs'        => $q['tariffs'],
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function show(string $id): void
    {
        $o = $this->find((int)$id);
        $this->view('orders/show', [
            'title'    => $o['number'],
            'o'        => $o,
            'garments' => Database::all('SELECT g.*, u.name operator FROM garments g LEFT JOIN users u ON u.id = g.assigned_to WHERE g.order_id = ? ORDER BY g.seq', [$o['id']]),
            'payments' => Database::all('SELECT p.*, u.name user FROM payments p LEFT JOIN users u ON u.id = p.user_id WHERE p.order_id = ? ORDER BY p.created_at', [$o['id']]),
            'methods'  => PaymentMethod::counter(),
            'printLabels' => input('etiquettes') === '1',
        ]);
    }

    /**
     * Étiquettes QR. La première impression est libre ; une réimpression exige un motif (journalisé).
     * ?manuel=1 : liste des codes à écrire à la main si l'imprimante est en panne.
     */
    public function labels(string $id): void
    {
        $o = $this->find((int)$id);
        $garments = Database::all('SELECT * FROM garments WHERE order_id = ? ORDER BY seq', [$o['id']]);
        $reason = $this->str('motif');
        $manual = input('manuel') === '1';
        if (!$manual && $reason === '' && \App\Services\LabelService::needsReason((int)$o['id'])) {
            $this->view('orders/labels_reason', ['title' => 'Réimprimer les étiquettes', 'o' => $o], 'layout');
            return;
        }
        \App\Services\LabelService::record((int)$o['id'], $manual, $reason);
        $this->view($manual ? 'orders/labels_manual' : 'orders/labels', ['o' => $o, 'garments' => $garments], null);
    }

    /** Ticket de dépôt (80 mm) : prix TTC, TVA incluse, NIU, reste à payer. */
    public function ticket(string $id): void
    {
        $o = $this->find((int)$id);
        $this->view('orders/ticket', [
            'o'        => $o,
            'garments' => Database::all('SELECT * FROM garments WHERE order_id = ? ORDER BY seq', [$o['id']]),
            'payments' => Database::all('SELECT method, amount, created_at FROM payments WHERE order_id = ? ORDER BY id', [$o['id']]),
        ], null);
    }

    public function pay(string $id): void
    {
        $o = $this->find((int)$id);
        $method = PaymentMethod::tryFrom($this->str('method')) ?? $this->fail('Mode de paiement invalide.');
        try {
            (new PaymentService())->record((int)$o['client_id'], $method, $this->int('amount'), orderId: (int)$o['id'], reference: $this->str('reference') ?: null);
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Paiement enregistré.', '/commandes/' . $o['id']);
    }

    public function pickup(string $id): void
    {
        $o = $this->find((int)$id);
        try {
            (new OrderService())->pickup((int)$o['id'], PaymentMethod::tryFrom($this->str('method')));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Commande remise au client.', Auth::can('counter') ? '/comptoir' : '/commandes/' . $o['id']);
    }

    public function cancel(string $id): void
    {
        try {
            (new OrderService())->cancel((int)$id, $this->str('reason'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Commande annulée.', '/commandes/' . (int)$id);
    }

    private function find(int $id): array
    {
        return Database::one(
            'SELECT o.*, c.name client, c.phone, c.code client_code, c.is_vip, c.type client_type, c.preferences, c.loyalty_points, a.name agency, u.name user
             FROM orders o JOIN clients c ON c.id = o.client_id JOIN agencies a ON a.id = o.agency_id LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?' . (Auth::scopedAgencyId() ? ' AND o.agency_id = ' . Auth::scopedAgencyId() : ''),
            [$id]
        ) ?? throw new HttpException(404, 'Commande introuvable');
    }
}
