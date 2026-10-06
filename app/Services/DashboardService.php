<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Domain\Step;

/** Indicateurs du cockpit direction. $agency = 0 → groupe consolidé. */
final class DashboardService
{
    private const AG = '(:ag = 0 OR o.agency_id = :ag)';

    public function __construct(private readonly int $agency = 0)
    {
    }

    private function p(array $extra = []): array
    {
        return ['ag' => $this->agency] + $extra;
    }

    public function today(): array
    {
        $t = Database::one(
            "SELECT COUNT(*) orders, COALESCE(SUM(o.total), 0) revenue, COUNT(DISTINCT o.client_id) clients
             FROM orders o WHERE o.created_at >= CURDATE() AND o.status <> 'annule' AND " . self::AG,
            $this->p()
        );
        $pieces = (int)Database::value(
            "SELECT COUNT(*) FROM garments g JOIN orders o ON o.id = g.order_id WHERE o.created_at >= CURDATE() AND o.status <> 'annule' AND " . self::AG,
            $this->p()
        );
        // Référence : moyenne des 4 mêmes jours de semaine précédents
        $ref = Database::one(
            "SELECT COUNT(*) / 4 orders, COALESCE(SUM(o.total), 0) / 4 revenue
             FROM orders o WHERE DATE(o.created_at) IN (CURDATE() - INTERVAL 7 DAY, CURDATE() - INTERVAL 14 DAY, CURDATE() - INTERVAL 21 DAY, CURDATE() - INTERVAL 28 DAY)
             AND o.status <> 'annule' AND " . self::AG,
            $this->p()
        );
        $refPieces = (float)Database::value(
            "SELECT COUNT(*) / 4 FROM garments g JOIN orders o ON o.id = g.order_id
             WHERE DATE(o.created_at) IN (CURDATE() - INTERVAL 7 DAY, CURDATE() - INTERVAL 14 DAY, CURDATE() - INTERVAL 21 DAY, CURDATE() - INTERVAL 28 DAY)
             AND o.status <> 'annule' AND " . self::AG,
            $this->p()
        );
        $orders = (int)$t['orders'];
        $revenue = (int)$t['revenue'];
        return [
            'revenue'     => $revenue,
            'orders'      => $orders,
            'pieces'      => $pieces,
            'basket'      => $orders ? (int)round($revenue / $orders) : 0,
            'clients'     => (int)$t['clients'],
            'new_clients' => (int)Database::value('SELECT COUNT(*) FROM clients WHERE created_at >= CURDATE()'),
            'ref_revenue' => (float)$ref['revenue'],
            'ref_orders'  => (float)$ref['orders'],
            'ref_pieces'  => $refPieces,
            'ref_basket'  => (float)$ref['orders'] > 0 ? (float)$ref['revenue'] / (float)$ref['orders'] : 0,
            'target'      => (int)(Database::value("SELECT target FROM objectives WHERE month = ? AND metric = 'ca_jour'", [date('Y-m')]) ?? 0),
        ];
    }

    /** Carte de production : nombre de pièces par étape, goulot éventuel. */
    public function production(): array
    {
        $stale = (int)Config::get('stale_minutes', 180);
        $rows = Database::all(
            "SELECT g.step, COUNT(*) n, SUM(g.status = 'bloque') blocked, SUM(TIMESTAMPDIFF(MINUTE, g.step_since, NOW()) > :stale) stale
             FROM garments g JOIN orders o ON o.id = g.order_id
             WHERE o.status IN ('en_atelier', 'pret') AND g.step NOT IN ('reception', 'retire') AND " . self::AG . '
             GROUP BY g.step',
            $this->p(['stale' => $stale])
        );
        $by = array_column($rows, null, 'step');
        $steps = [];
        foreach (Step::production() as $s) {
            $r = $by[$s->value] ?? [];
            $steps[$s->value] = ['label' => $s->label(), 'n' => (int)($r['n'] ?? 0), 'blocked' => (int)($r['blocked'] ?? 0), 'stale' => (int)($r['stale'] ?? 0)];
        }
        $work = array_filter($steps, fn($k) => $k !== 'pret', ARRAY_FILTER_USE_KEY);
        $avg = count($work) ? array_sum(array_column($work, 'n')) / count($work) : 0;
        $bottleneck = null;
        $max = 0;
        foreach ($work as $k => $s) {
            if ($s['n'] > $max) {
                $max = $s['n'];
                $bottleneck = $k;
            }
        }
        if ($max < 10 || $max < 1.5 * $avg) {
            $bottleneck = null;
        }
        return [
            'steps'      => $steps,
            'total'      => array_sum(array_column($work, 'n')),
            'max'        => max(1, ...array_column($steps, 'n')),
            'bottleneck' => $bottleneck,
            'load_pct'   => $avg > 0 && $bottleneck ? (int)round($max * 100 / $avg) : 0,
        ];
    }

    /** Commandes en retard ou à risque, avec l'étape la moins avancée. */
    public function riskyOrders(): array
    {
        $orders = Database::all(
            "SELECT o.id, o.number, o.promised_at, o.status, c.name client, c.is_vip, c.type, COUNT(g.id) pcs
             FROM orders o JOIN clients c ON c.id = o.client_id JOIN garments g ON g.order_id = o.id
             WHERE o.status = 'en_atelier' AND o.promised_at < NOW() + INTERVAL :h HOUR AND " . self::AG . '
             GROUP BY o.id, o.number, o.promised_at, o.status, c.name, c.is_vip, c.type
             ORDER BY o.promised_at LIMIT 12',
            $this->p(['h' => (int)Config::get('risk_orange_hours', 3)])
        );
        foreach ($orders as &$o) {
            $o['worst'] = Database::one(
                "SELECT g.step, g.status, g.step_since, u.name operator FROM garments g LEFT JOIN users u ON u.id = g.assigned_to
                 WHERE g.order_id = ? AND g.step NOT IN ('pret', 'retire')
                 ORDER BY g.status = 'bloque' DESC, " . Step::sqlOrder('g.step') . ' LIMIT 1',
                [$o['id']]
            );
        }
        return $orders;
    }

    public function alerts(): array
    {
        $alerts = [];
        foreach (Database::all(
            "SELECT a.name agency, cs.label, SUM(cc.expected) expected, SUM(cc.counted) counted
             FROM cash_sessions cs JOIN cash_counts cc ON cc.cash_session_id = cs.id JOIN agencies a ON a.id = cs.agency_id
             WHERE cs.closed_at >= CURDATE() AND (:ag = 0 OR cs.agency_id = :ag)
             GROUP BY cs.id, a.name, cs.label HAVING SUM(cc.counted) <> SUM(cc.expected)",
            $this->p()
        ) as $r) {
            $alerts[] = ['red', "Écart de caisse — {$r['agency']} ({$r['label']})", 'Théorique ' . money($r['expected']) . ' · réel ' . money($r['counted']) . ' · écart ' . money($r['counted'] - $r['expected']), '/caisse'];
        }
        foreach (Database::all("SELECT id, name, credit_limit FROM clients WHERE type = 'pro' AND credit_limit > 0") as $c) {
            $out = ClientService::outstanding((int)$c['id']);
            if ($out > (int)$c['credit_limit']) {
                $alerts[] = ['red', "Encours > plafond — {$c['name']}", money($out) . ' / plafond ' . money($c['credit_limit']), '/recouvrement'];
            }
        }
        foreach (Database::all('SELECT name, unit, quantity, min_qty FROM stock_items WHERE quantity < min_qty') as $s) {
            $alerts[] = ['orange', "Stock critique — {$s['name']}", rtrim(rtrim(number_format((float)$s['quantity'], 1, ',', ' '), '0'), ',') . " {$s['unit']} restants · minimum " . (float)$s['min_qty'] . " {$s['unit']}", '/stocks'];
        }
        $rate = QualityService::reworkRate(7);
        $target = (float)(Database::value("SELECT target FROM objectives WHERE month = ? AND metric = 'reprise_max'", [date('Y-m')]) ?? 3);
        if ($rate > $target) {
            $alerts[] = ['orange', 'Taux de reprise en hausse', str_replace('.', ',', (string)$rate) . " % sur 7 j · objectif ≤ {$target} %", '/qualite'];
        }
        foreach ($this->managerial() as $a) {
            $alerts[] = $a;
        }
        $blocked = (int)Database::value("SELECT COUNT(*) FROM garments g JOIN orders o ON o.id = g.order_id WHERE g.status = 'bloque' AND " . self::AG, $this->p());
        if ($blocked > 0) {
            $alerts[] = ['red', "$blocked pièce" . ($blocked > 1 ? 's bloquées' : ' bloquée') . ' en atelier', 'Incident machine ou pièce à arbitrer', '/production'];
        }
        return $alerts;
    }

    /**
     * Alertes managériales sur les indicateurs : CA de la veille en baisse, retards, réclamations ouvertes, productivité.
     * La veille est comparée à la moyenne des 4 mêmes jours de semaine précédents ; les seuils sont des paramètres.
     * @return list<array{0:string,1:string,2:string,3:string,4:string}> [ton, titre, détail, lien, clé]
     */
    public function managerial(?int $now = null): array
    {
        $now ??= time();
        $day = fn(int $back) => date('Y-m-d', $now - $back * 86400);
        $out = [];
        $rev = fn(string $d) => (int)Database::value("SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE DATE(o.created_at) = :d AND o.status <> 'annule' AND " . self::AG, $this->p(['d' => $d]));
        $done = fn(string $d) => (int)Database::value(
            "SELECT COUNT(DISTINCT e.garment_id) FROM garment_events e JOIN garments g ON g.id = e.garment_id JOIN orders o ON o.id = g.order_id WHERE e.step = 'pret' AND DATE(e.created_at) = :d AND " . self::AG,
            $this->p(['d' => $d])
        );
        $refDays = [$day(8), $day(15), $day(22), $day(29)];

        $drop = (int)SettingsService::get('kpi.revenue_drop_pct');
        $ref = array_sum(array_map($rev, $refDays)) / 4;
        $yesterday = $rev($day(1));
        if ($drop > 0 && $ref > 0 && $yesterday < $ref * (1 - $drop / 100)) {
            $out[] = ['red', 'CA d\'hier en baisse de ' . (int)round(100 - $yesterday * 100 / $ref) . ' %', money($yesterday) . ' FCFA contre ' . money((int)round($ref)) . ' en moyenne les mêmes jours', '/bi', 'kpi:revenue'];
        }

        $prodDrop = (int)SettingsService::get('kpi.productivity_drop_pct');
        $refDone = array_sum(array_map($done, $refDays)) / 4;
        $doneY = $done($day(1));
        if ($prodDrop > 0 && $refDone > 0 && $doneY < $refDone * (1 - $prodDrop / 100)) {
            $out[] = ['orange', 'Productivité en baisse : ' . $doneY . ' pièces terminées hier', 'Moyenne des mêmes jours : ' . (int)round($refDone) . ' pièces', '/production', 'kpi:productivity'];
        }

        $lateMin = (int)SettingsService::get('kpi.risk_orders_min');
        $late = (int)Database::value("SELECT COUNT(*) FROM orders o WHERE o.status = 'en_atelier' AND o.promised_at < :now AND " . self::AG, $this->p(['now' => date('Y-m-d H:i:s', $now)]));
        if ($lateMin > 0 && $late >= $lateMin) {
            $out[] = ['red', "$late commandes en retard", 'Au-delà du seuil de ' . $lateMin . ' : arbitrer la charge de l\'atelier', '/commandes?tab=retard', 'kpi:late'];
        }

        $claimMin = (int)SettingsService::get('kpi.complaints_min');
        $claims = (int)Database::value("SELECT COUNT(*) FROM complaints c LEFT JOIN orders o ON o.id = c.order_id WHERE c.status <> 'cloturee' AND (:ag = 0 OR o.agency_id = :ag)", $this->p());
        if ($claimMin > 0 && $claims >= $claimMin) {
            $out[] = ['orange', "$claims réclamations ouvertes", 'Seuil de ' . $claimMin . ' atteint : traiter les plus anciennes', '/qualite', 'kpi:claims'];
        }
        return $out;
    }

    /** Fraîcheur des données affichées (SE22) : quand elles ont été calculées et d'où elles viennent. */
    public function freshness(?int $now = null): array
    {
        $now ??= time();
        return [
            'computed_at'  => date('Y-m-d H:i:s', $now),
            'last_order'   => Database::value('SELECT MAX(o.created_at) FROM orders o WHERE ' . self::AG, $this->p()),
            'last_payment' => Database::value('SELECT MAX(p.created_at) FROM payments p LEFT JOIN cash_sessions cs ON cs.id = p.cash_session_id WHERE (:ag = 0 OR cs.agency_id = :ag)', $this->p()),
            'last_sync'    => Database::value('SELECT MAX(received_at) FROM offline_syncs'),
            'rejected'     => (int)Database::value("SELECT COUNT(*) FROM offline_syncs WHERE status = 'rejected' AND received_at > ?", [date('Y-m-d H:i:s', $now - 7 * 86400)]),
        ];
    }

    public function money(): array
    {
        $pay = Database::one(
            "SELECT COALESCE(SUM(p.amount), 0) total, COALESCE(SUM(CASE WHEN p.method IN ('orange','mtn') THEN p.amount ELSE 0 END), 0) momo
             FROM payments p LEFT JOIN cash_sessions cs ON cs.id = p.cash_session_id
             WHERE p.created_at >= CURDATE() AND (:ag = 0 OR cs.agency_id = :ag)",
            $this->p()
        );
        $receivables = Database::one(
            "SELECT COALESCE(SUM(total - paid), 0) total, COALESCE(SUM(CASE WHEN due_date < CURDATE() - INTERVAL 60 DAY THEN total - paid ELSE 0 END), 0) old
             FROM invoices WHERE status <> 'payee' AND kind = 'facture' AND (:ag = 0 OR agency_id = :ag)",
            $this->p()
        );
        $unbilled = (int)Database::value("SELECT COALESCE(SUM(o.total - o.paid), 0) FROM orders o WHERE o.on_account = 1 AND o.invoice_id IS NULL AND o.status <> 'annule' AND " . self::AG, $this->p());
        $uncollected = Database::one(
            "SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN o.on_account = 0 THEN o.total - o.paid ELSE 0 END), 0) amount, SUM(o.ready_at < NOW() - INTERVAL 15 DAY) old
             FROM orders o WHERE o.status = 'pret' AND " . self::AG,
            $this->p()
        );
        return [
            'cashed'      => (int)$pay['total'],
            'momo_share'  => pct((float)$pay['momo'], (float)$pay['total']),
            'receivables' => (int)$receivables['total'] + $unbilled,
            'receivables_old' => (int)$receivables['old'],
            'reworks'     => (int)Database::value("SELECT COUNT(*) FROM quality_checks WHERE result = 'reprise' AND created_at >= CURDATE()"),
            'complaints'  => (int)Database::value("SELECT COUNT(*) FROM complaints WHERE status <> 'cloturee'"),
            'incidents'   => (int)Database::value("SELECT COUNT(*) FROM garment_events WHERE action = 'incident' AND created_at >= CURDATE()"),
            'uncollected' => (int)$uncollected['n'],
            'uncollected_amount' => (int)$uncollected['amount'],
            'uncollected_old'    => (int)$uncollected['old'],
        ];
    }

    public function objectives(): array
    {
        $month = date('Y-m');
        $targets = [];
        foreach (Database::all('SELECT metric, target FROM objectives WHERE month = ?', [$month]) as $r) {
            $targets[$r['metric']] = (int)$r['target'];
        }
        $ca = (int)Database::value(
            "SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.created_at >= :m AND o.status <> 'annule' AND " . self::AG,
            $this->p(['m' => $month . '-01'])
        );
        return [
            'day'         => (int)date('j'),
            'days'        => (int)date('t'),
            'ca'          => $ca,
            'ca_target'   => $targets['ca'] ?? 0,
            'recovered'   => (int)Database::value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id IS NOT NULL AND created_at >= ?', [$month . '-01']),
            'rec_target'  => $targets['recouvrement'] ?? 0,
            'rework'      => QualityService::reworkRate(30),
            'rework_max'  => $targets['reprise_max'] ?? 3,
        ];
    }

    public function all(): array
    {
        return [
            'today'      => $this->today(),
            'prod'       => $this->production(),
            'risky'      => $this->riskyOrders(),
            'alerts'     => $this->alerts(),
            'money'      => $this->money(),
            'objectives' => $this->objectives(),
            'fresh'      => $this->freshness(),
        ];
    }
}
