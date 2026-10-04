<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Services\WorkflowService;

final class TraceController extends Controller
{
    public function index(): void
    {
        $q = strtoupper($this->str('q'));
        $garment = null;
        $order = null;
        $garments = [];
        $events = [];
        if (preg_match('/^PR-\d{4}-\d{6}-\d{2,}$/', $q)) {
            $garment = Database::one(
                'SELECT g.*, o.number, o.id order_id, o.created_at order_created, o.promised_at, c.name client, a.name agency
                 FROM garments g JOIN orders o ON o.id = g.order_id JOIN clients c ON c.id = o.client_id JOIN agencies a ON a.id = o.agency_id
                 WHERE g.code = ?' . (Auth::scopedAgencyId() ? ' AND o.agency_id = ' . Auth::scopedAgencyId() : ''),
                [$q]
            );
            if ($garment) {
                $events = WorkflowService::events((int)$garment['id']);
            }
        } elseif ($q !== '') {
            $order = Database::one('SELECT o.*, c.name client FROM orders o JOIN clients c ON c.id = o.client_id WHERE o.number = ?' . (Auth::scopedAgencyId() ? ' AND o.agency_id = ' . Auth::scopedAgencyId() : ''), [$q]);
            if ($order) {
                $garments = Database::all('SELECT * FROM garments WHERE order_id = ? ORDER BY seq', [$order['id']]);
            }
        }
        if ($q !== '' && !$garment && !$order) {
            flash('err', "Aucune pièce ni commande pour « $q ».");
        }
        $recent = Database::all(
            "SELECT e.*, g.code, g.label, u.name user FROM garment_events e JOIN garments g ON g.id = e.garment_id LEFT JOIN users u ON u.id = e.user_id
             WHERE e.action IN ('incident', 'reprise') ORDER BY e.id DESC LIMIT 15"
        );
        $this->view('trace/index', compact('q', 'garment', 'order', 'garments', 'events', 'recent') + ['title' => 'Traçabilité']);
    }
}
