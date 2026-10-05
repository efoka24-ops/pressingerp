<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Services\CashService;
use App\Services\ClientService;
use App\Services\DashboardService;

final class HomeController extends Controller
{
    public function index(): void
    {
        // Personnel connecté : son accueil. Visiteur (client) : la page de suivi de commande.
        if (Auth::role()) {
            redirect(Auth::role()->home());
        }
        $this->view('home/landing', ['title' => 'Suivre ma commande'], 'public');
    }

    /** Accueil de l'agent de comptoir */
    public function counter(): void
    {
        $ag = Auth::agencyId();
        $pickups = Database::all(
            "SELECT o.id, o.number, o.status, o.total, o.paid, o.on_account, o.rail, o.promised_at, c.name client, c.is_vip, c.type, COUNT(g.id) pcs
             FROM orders o JOIN clients c ON c.id = o.client_id JOIN garments g ON g.order_id = o.id
             WHERE o.agency_id = ? AND (o.status = 'pret' OR (o.status = 'en_atelier' AND o.promised_at < CURDATE() + INTERVAL 1 DAY))
             GROUP BY o.id, o.number, o.status, o.total, o.paid, o.on_account, o.rail, o.promised_at, c.name, c.is_vip, c.type
             ORDER BY o.status = 'pret' DESC, o.promised_at LIMIT 15",
            [$ag]
        );
        $cash = new CashService();
        $session = $cash->current(Auth::id());
        $stats = Database::one(
            "SELECT (SELECT COUNT(*) FROM orders WHERE user_id = :u AND created_at >= CURDATE()) deposits,
                    (SELECT COUNT(*) FROM orders WHERE picked_up_by = :u AND picked_up_at >= CURDATE()) pickups,
                    (SELECT COUNT(*) FROM clients WHERE created_at >= CURDATE()) new_clients",
            ['u' => Auth::id()]
        );
        $late = array_filter($pickups, fn($o) => $o['status'] === 'en_atelier' && strtotime($o['promised_at']) < time());
        $this->view('home/counter', [
            'title'    => 'Accueil comptoir',
            'pickups'  => $pickups,
            'readyN'   => count(array_filter($pickups, fn($o) => $o['status'] === 'pret')),
            'late'     => $late,
            'session'  => $session,
            'expected' => $session ? $cash->expected($session) : [],
            'stats'    => $stats,
        ]);
    }

    public function cockpit(): void
    {
        $ag = Auth::scopedAgencyId() ?: $this->int('agence');
        $this->view('home/cockpit', (new DashboardService($ag))->all() + [
            'title'    => 'Cockpit direction',
            'ag'       => $ag,
            'agencies' => Database::all('SELECT id, name FROM agencies WHERE is_workshop = 0 ORDER BY id'),
        ]);
    }

    /** Recherche universelle : n° pièce, n° commande, facture, téléphone, nom */
    public function search(): void
    {
        $q = $this->str('q');
        $Q = strtoupper($q);
        if (preg_match('/^PR-\d{4}-\d{6}-\d{2,}$/', $Q)) {
            redirect(Auth::can('production') ? '/scan?code=' . urlencode($Q) : '/tracabilite?q=' . urlencode($Q));
        }
        if (preg_match('/^PR-\d{4}-\d{6}$/', $Q) && ($id = Database::value('SELECT id FROM orders WHERE number = ?' . Auth::scopeSql(), [$Q]))) {
            redirect('/commandes/' . $id);
        }
        if (preg_match('/^FA-\d{4}-\d{5}$/', $Q) && ($id = Database::value('SELECT id FROM invoices WHERE number = ?', [$Q]))) {
            redirect('/commercial/factures/' . $id);
        }
        $clients = [];
        $orders = [];
        if (mb_strlen($q) >= 2) {
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $clients = Database::all(
                'SELECT id, code, name, phone, is_vip, type FROM clients WHERE name LIKE :q OR code LIKE :q' . (strlen($digits) >= 4 ? ' OR phone LIKE :d' : '') . ' ORDER BY name LIMIT 20',
                ['q' => "%$q%"] + (strlen($digits) >= 4 ? ['d' => "%$digits%"] : [])
            );
            $orders = Database::all(
                'SELECT o.id, o.number, o.status, o.total, o.created_at, c.name client FROM orders o JOIN clients c ON c.id = o.client_id WHERE o.number LIKE ?' . Auth::scopeSql('o.agency_id') . ' ORDER BY o.id DESC LIMIT 20',
                ["%$Q%"]
            );
        }
        if (count($clients) === 1 && !$orders && Auth::can('clients')) {
            redirect('/clients/' . $clients[0]['id']);
        }
        $this->view('home/search', ['title' => 'Recherche', 'q' => $q, 'clients' => $clients, 'orders' => $orders]);
    }
}
