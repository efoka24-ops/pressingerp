<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Services\DashboardService;

final class BIController extends Controller
{
    public function index(): void
    {
        // Périmètre : une agence demandée hors de ses droits est refusée et journalisée (SE23)
        $ag = Auth::resolveAgencyScope($this->int('agence'), 'bi');
        $and = $ag ? ' AND agency_id = ' . $ag : '';
        $andO = $ag ? ' AND o.agency_id = ' . $ag : '';
        $agencies = Database::all('SELECT id, name FROM agencies WHERE is_workshop = 0' . ($ag ? ' AND id = ' . $ag : '') . ' ORDER BY id');
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = date('Y-m', strtotime("first day of -$i month"));
        }
        $matrix = array_fill_keys($months, array_fill_keys(array_column($agencies, 'id'), 0));
        foreach ($this->monthly($ag) as $r) {
            if (isset($matrix[$r['m']][(int)$r['agency_id']])) {
                $matrix[$r['m']][(int)$r['agency_id']] = (int)$r['total'];
            }
        }
        $max = max(1, ...array_values(array_map('array_sum', $matrix)));

        $mix = Database::one(
            "SELECT COALESCE(SUM(CASE WHEN on_account = 1 THEN total - delivery_fee END), 0) pros,
                    COALESCE(SUM(CASE WHEN on_account = 0 AND service_level = 'standard' THEN total - delivery_fee END), 0) particuliers,
                    COALESCE(SUM(CASE WHEN on_account = 0 AND service_level IN ('express', 'vip') THEN total - delivery_fee END), 0) premium,
                    COALESCE(SUM(delivery_fee), 0) livraison
             FROM orders WHERE status <> 'annule' AND created_at >= CURDATE() - INTERVAL 12 MONTH" . $and
        );

        $heat = [];
        foreach (Database::all(
            "SELECT WEEKDAY(created_at) d, FLOOR(HOUR(created_at) / 2) * 2 h, COUNT(*) n
             FROM orders WHERE created_at > NOW() - INTERVAL 90 DAY AND HOUR(created_at) BETWEEN 8 AND 19" . $and . ' GROUP BY d, h'
        ) as $r) {
            $heat[(int)$r['d']][(int)$r['h']] = (int)$r['n'];
        }
        $heatMax = 1;
        foreach ($heat as $row) {
            $heatMax = max($heatMax, ...array_values($row));
        }

        $kpi = Database::one(
            "SELECT COALESCE(SUM(total), 0) revenue, COUNT(*) orders, COUNT(DISTINCT client_id) clients
             FROM orders WHERE status <> 'annule' AND created_at >= CURDATE() - INTERVAL 12 MONTH" . $and
        );
        $prev = (int)Database::value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status <> 'annule' AND created_at >= CURDATE() - INTERVAL 24 MONTH AND created_at < CURDATE() - INTERVAL 12 MONTH" . $and);

        $this->view('bi/index', [
            'title'    => 'Business Intelligence',
            'ag'       => $ag,
            'allAgencies' => Auth::scopedAgencyId() ? [] : Database::all('SELECT id, name FROM agencies WHERE is_workshop = 0 ORDER BY id'),
            'fresh'    => (new DashboardService($ag))->freshness(),
            'agencies' => $agencies,
            'matrix'   => $matrix,
            'max'      => $max,
            'mix'      => array_map('intval', $mix),
            'heat'     => $heat,
            'heatMax'  => $heatMax,
            'kpi'      => $kpi,
            'growth'   => $prev > 0 ? round(((int)$kpi['revenue'] - $prev) * 100 / $prev, 1) : null,
            'top'      => Database::all(
                "SELECT c.id, c.name, c.type, c.is_vip, COUNT(o.id) n, SUM(o.total) revenue FROM orders o JOIN clients c ON c.id = o.client_id
                 WHERE o.status <> 'annule' AND o.created_at >= CURDATE() - INTERVAL 12 MONTH" . $andO . ' GROUP BY c.id, c.name, c.type, c.is_vip ORDER BY revenue DESC LIMIT 10'
            ),
            'articles' => Database::all(
                "SELECT g.label, COUNT(*) n, SUM(g.price) revenue FROM garments g JOIN orders o ON o.id = g.order_id
                 WHERE o.status <> 'annule' AND o.created_at >= CURDATE() - INTERVAL 12 MONTH" . $andO . ' GROUP BY g.label ORDER BY n DESC LIMIT 8'
            ),
            'delay'    => (float)Database::value('SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, ready_at)) FROM orders WHERE ready_at IS NOT NULL AND created_at >= CURDATE() - INTERVAL 90 DAY' . $and),
            'onTime'   => pct((float)Database::value('SELECT COUNT(*) FROM orders WHERE ready_at IS NOT NULL AND ready_at <= promised_at AND created_at >= CURDATE() - INTERVAL 90 DAY' . $and), (float)Database::value('SELECT COUNT(*) FROM orders WHERE ready_at IS NOT NULL AND created_at >= CURDATE() - INTERVAL 90 DAY' . $and)),
        ]);
    }

    public function export(): void
    {
        $ag = Auth::resolveAgencyScope($this->int('agence'), 'bi.export');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ca-mensuel-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Mois', 'Agence', 'CA (FCFA)', 'Commandes'], ';');
        foreach ($this->monthly($ag) as $r) {
            fputcsv($out, [$r['m'], $r['agency'], $r['total'], $r['n']], ';');
        }
        fclose($out);
        exit;
    }

    /** CA mensuel par agence sur 12 mois ; $ag = 0 pour tout le groupe. */
    private function monthly(int $ag = 0): array
    {
        return Database::all(
            "SELECT DATE_FORMAT(o.created_at, '%Y-%m') m, o.agency_id, a.name agency, SUM(o.total) total, COUNT(*) n
             FROM orders o JOIN agencies a ON a.id = o.agency_id
             WHERE o.status <> 'annule' AND o.created_at >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01')" . ($ag ? ' AND o.agency_id = ' . $ag : '') . '
             GROUP BY m, o.agency_id, a.name ORDER BY m, o.agency_id'
        );
    }
}
