<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Domain\Step;

final class ProductionController extends Controller
{
    public function index(): void
    {
        $rows = Database::all(
            "SELECT g.id, g.code, g.label, g.step, g.status, g.step_since, g.rework_count, g.damages,
                    o.number, o.promised_at, o.service_level, c.name client, c.is_vip, u.name operator
             FROM garments g JOIN orders o ON o.id = g.order_id JOIN clients c ON c.id = o.client_id LEFT JOIN users u ON u.id = g.assigned_to
             WHERE o.status IN ('en_atelier', 'pret') AND g.step NOT IN ('reception', 'retire')
             ORDER BY g.status = 'bloque' DESC, o.service_level = 'express' DESC, o.promised_at ASC"
        );
        $columns = [];
        foreach (Step::production() as $s) {
            $columns[$s->value] = ['step' => $s, 'items' => [], 'count' => 0, 'blocked' => 0];
        }
        foreach ($rows as $r) {
            $col = &$columns[$r['step']];
            $col['count']++;
            $col['blocked'] += $r['status'] === 'bloque' ? 1 : 0;
            if (count($col['items']) < 40) {
                $col['items'][] = $r;
            }
            unset($col);
        }
        $operators = Database::all(
            "SELECT u.id, u.name, COUNT(g.id) busy FROM users u LEFT JOIN garments g ON g.assigned_to = u.id AND g.status = 'en_cours'
             WHERE u.role = 'atelier' AND u.active = 1 GROUP BY u.id, u.name ORDER BY u.name"
        );
        $throughput = (int)Database::value("SELECT COUNT(*) FROM garment_events WHERE action = 'termine' AND created_at > NOW() - INTERVAL 1 HOUR");
        $this->view('production/index', ['title' => 'Production', 'columns' => $columns, 'operators' => $operators, 'throughput' => $throughput]);
    }
}
