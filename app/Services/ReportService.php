<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Rapports journalier et mensuel. Chaque chiffre est calculé directement en SQL sur les écritures (commandes, paiements,
 * caisses, factures), jamais sur un agrégat intermédiaire : ils se rapprochent donc exactement du cockpit (SC-003).
 * $agency = 0 → groupe consolidé.
 */
final class ReportService
{
    private const AG = '(:ag = 0 OR o.agency_id = :ag)';

    /** @return array<string,mixed> */
    public static function daily(string $date, int $agency = 0): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            throw new \DomainException('Date invalide.');
        }
        $p = ['ag' => $agency, 'd' => $date];
        $orders = Database::one("SELECT COUNT(*) n, COALESCE(SUM(o.total), 0) revenue, COALESCE(SUM(CASE WHEN o.on_account = 1 THEN o.total ELSE 0 END), 0) on_account
                                 FROM orders o WHERE DATE(o.created_at) = :d AND o.status <> 'annule' AND " . self::AG, $p);
        $cancelled = (int)Database::value("SELECT COUNT(*) FROM orders o WHERE DATE(o.created_at) = :d AND o.status = 'annule' AND " . self::AG, $p);
        $pieces = (int)Database::value("SELECT COUNT(*) FROM garments g JOIN orders o ON o.id = g.order_id WHERE DATE(o.created_at) = :d AND o.status <> 'annule' AND " . self::AG, $p);
        $payments = Database::all(
            "SELECT p.method, COUNT(*) n, COALESCE(SUM(p.amount), 0) total FROM payments p LEFT JOIN cash_sessions cs ON cs.id = p.cash_session_id
             WHERE DATE(p.created_at) = :d AND (:ag = 0 OR cs.agency_id = :ag) GROUP BY p.method ORDER BY total DESC",
            $p
        );
        $sessions = Database::all(
            "SELECT cs.id, cs.label, u.name agent, a.name agency, cs.variance, cs.justification FROM cash_sessions cs JOIN users u ON u.id = cs.user_id JOIN agencies a ON a.id = cs.agency_id
             WHERE DATE(cs.closed_at) = :d AND (:ag = 0 OR cs.agency_id = :ag) ORDER BY cs.id",
            $p
        );
        return [
            'date'       => $date,
            'agency'     => $agency,
            'orders'     => (int)$orders['n'],
            'revenue'    => (int)$orders['revenue'],
            'on_account' => (int)$orders['on_account'],
            'cancelled'  => $cancelled,
            'pieces'     => $pieces,
            'basket'     => (int)$orders['n'] ? (int)round($orders['revenue'] / $orders['n']) : 0,
            'new_clients' => (int)Database::value(
                'SELECT COUNT(DISTINCT c.id) FROM clients c JOIN orders o ON o.client_id = c.id WHERE DATE(c.created_at) = :d AND DATE(o.created_at) = :d AND ' . self::AG,
                $p
            ),
            'ready'      => (int)Database::value('SELECT COUNT(*) FROM orders o WHERE DATE(o.ready_at) = :d AND ' . self::AG, $p),
            'collected'  => (int)Database::value("SELECT COUNT(*) FROM orders o WHERE DATE(o.picked_up_at) = :d AND o.status IN ('retire', 'livre') AND " . self::AG, $p),
            'delivered'  => (int)Database::value("SELECT COUNT(*) FROM deliveries d WHERE DATE(d.delivered_at) = :d AND d.status = 'livre' AND (:ag = 0 OR d.agency_id = :ag)", $p),
            'payments'   => $payments,
            'cashed'     => array_sum(array_map(fn($r) => (int)$r['total'], $payments)),
            'sessions'   => $sessions,
            'variance'   => array_sum(array_map(fn($s) => (int)$s['variance'], $sessions)),
            'invoiced'   => (int)Database::value("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE DATE(created_at) = :d AND kind = 'facture' AND (:ag = 0 OR agency_id = :ag)", $p),
            'complaints' => (int)Database::value('SELECT COUNT(*) FROM complaints c LEFT JOIN orders o ON o.id = c.order_id WHERE DATE(c.created_at) = :d AND (:ag = 0 OR o.agency_id = :ag)', $p),
            'incidents'  => (int)Database::value("SELECT COUNT(*) FROM garment_events e JOIN garments g ON g.id = e.garment_id JOIN orders o ON o.id = g.order_id WHERE e.action = 'incident' AND DATE(e.created_at) = :d AND " . self::AG, $p),
            'reworks'    => (int)Database::value("SELECT COUNT(*) FROM quality_checks q JOIN garments g ON g.id = q.garment_id JOIN orders o ON o.id = g.order_id WHERE q.result = 'reprise' AND DATE(q.created_at) = :d AND " . self::AG, $p),
        ];
    }

    /** @return array<string,mixed> */
    public static function monthly(string $month, int $agency = 0): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month) || strtotime($month . '-01') === false) {
            throw new \DomainException('Mois invalide.');
        }
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));
        $prevStart = date('Y-m-01', strtotime($start . ' -1 month'));
        $prevEnd = date('Y-m-t', strtotime($prevStart));
        $p = ['ag' => $agency, 's' => $start, 'e' => $end];
        $between = 'DATE(o.created_at) BETWEEN :s AND :e';
        $o = Database::one("SELECT COUNT(*) n, COALESCE(SUM(o.total), 0) revenue, COALESCE(SUM(CASE WHEN o.on_account = 1 THEN o.total ELSE 0 END), 0) on_account
                            FROM orders o WHERE $between AND o.status <> 'annule' AND " . self::AG, $p);
        $prev = (int)Database::value("SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE DATE(o.created_at) BETWEEN :s AND :e AND o.status <> 'annule' AND " . self::AG, ['ag' => $agency, 's' => $prevStart, 'e' => $prevEnd]);
        $checks = Database::one(
            "SELECT COUNT(*) n, COALESCE(SUM(q.result = 'reprise'), 0) reworks FROM quality_checks q JOIN garments g ON g.id = q.garment_id JOIN orders o ON o.id = g.order_id
             WHERE DATE(q.created_at) BETWEEN :s AND :e AND " . self::AG,
            $p
        );
        $payments = Database::all(
            "SELECT p.method, COUNT(*) n, COALESCE(SUM(p.amount), 0) total FROM payments p LEFT JOIN cash_sessions cs ON cs.id = p.cash_session_id
             WHERE DATE(p.created_at) BETWEEN :s AND :e AND (:ag = 0 OR cs.agency_id = :ag) GROUP BY p.method ORDER BY total DESC",
            $p
        );
        return [
            'month'      => $month,
            'agency'     => $agency,
            'orders'     => (int)$o['n'],
            'revenue'    => (int)$o['revenue'],
            'on_account' => (int)$o['on_account'],
            'prev_revenue' => $prev,
            'growth'     => $prev > 0 ? round(((int)$o['revenue'] - $prev) * 100 / $prev, 1) : null,
            'basket'     => (int)$o['n'] ? (int)round($o['revenue'] / $o['n']) : 0,
            'pieces'     => (int)Database::value("SELECT COUNT(*) FROM garments g JOIN orders o ON o.id = g.order_id WHERE $between AND o.status <> 'annule' AND " . self::AG, $p),
            'days'       => Database::all("SELECT DATE(o.created_at) d, COUNT(*) n, COALESCE(SUM(o.total), 0) revenue FROM orders o WHERE $between AND o.status <> 'annule' AND " . self::AG . ' GROUP BY d ORDER BY d', $p),
            'agencies'   => Database::all("SELECT a.name, COUNT(*) n, COALESCE(SUM(o.total), 0) revenue FROM orders o JOIN agencies a ON a.id = o.agency_id WHERE $between AND o.status <> 'annule' AND " . self::AG . ' GROUP BY a.id, a.name ORDER BY revenue DESC', $p),
            'payments'   => $payments,
            'cashed'     => array_sum(array_map(fn($r) => (int)$r['total'], $payments)),
            'invoiced'   => (int)Database::value("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE DATE(created_at) BETWEEN :s AND :e AND kind = 'facture' AND (:ag = 0 OR agency_id = :ag)", $p),
            'credited'   => (int)Database::value("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE DATE(created_at) BETWEEN :s AND :e AND kind = 'avoir' AND (:ag = 0 OR agency_id = :ag)", $p),
            'checks'     => (int)$checks['n'],
            'rework_rate' => pct((float)$checks['reworks'], (float)$checks['n'], 1),
            'complaints' => (int)Database::value('SELECT COUNT(*) FROM complaints c LEFT JOIN orders o ON o.id = c.order_id WHERE DATE(c.created_at) BETWEEN :s AND :e AND (:ag = 0 OR o.agency_id = :ag)', $p),
            'delivered'  => (int)Database::value("SELECT COUNT(*) FROM deliveries d WHERE DATE(d.delivered_at) BETWEEN :s AND :e AND d.status = 'livre' AND (:ag = 0 OR d.agency_id = :ag)", $p),
            'top'        => Database::all("SELECT c.name, COUNT(o.id) n, SUM(o.total) revenue FROM orders o JOIN clients c ON c.id = o.client_id WHERE $between AND o.status <> 'annule' AND " . self::AG . ' GROUP BY c.id, c.name ORDER BY revenue DESC LIMIT 5', $p),
        ];
    }

    /** Relevé de compte d'un client sur une période : commandes, factures, règlements, solde. */
    public static function statement(int $clientId, string $from, string $to): array
    {
        $client = Database::one('SELECT id, code, name, phone, address, niu, type FROM clients WHERE id = ?', [$clientId]) ?? throw new \DomainException('Client introuvable.');
        foreach ([$from, $to] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new \DomainException('Période invalide.');
            }
        }
        if ($to < $from) {
            throw new \DomainException('La date de fin précède la date de début.');
        }
        $p = ['c' => $clientId, 'f' => $from, 't' => $to];
        return [
            'client'   => $client,
            'from'     => $from,
            'to'       => $to,
            'orders'   => Database::all("SELECT o.number, o.created_at, o.total, o.paid, o.on_account, o.status FROM orders o WHERE o.client_id = :c AND o.status <> 'annule' AND DATE(o.created_at) BETWEEN :f AND :t ORDER BY o.created_at", $p),
            'invoices' => Database::all("SELECT number, kind, created_at, due_date, total, paid FROM invoices WHERE client_id = :c AND DATE(created_at) BETWEEN :f AND :t ORDER BY created_at", $p),
            'payments' => Database::all('SELECT created_at, method, amount, reference, receipt_no FROM payments WHERE client_id = :c AND DATE(created_at) BETWEEN :f AND :t ORDER BY created_at, id', $p),
            'outstanding' => ClientService::outstanding($clientId),
        ];
    }
}
