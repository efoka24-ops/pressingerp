<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

final class InvoiceService
{
    /** Facturation mensuelle groupée des commandes "en compte" d'un mois (AAAA-MM). */
    public function generate(string $month): int
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new \DomainException('Mois invalide.');
        }
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));
        if ($start > date('Y-m-d')) {
            throw new \DomainException('Impossible de facturer un mois futur.');
        }
        $rows = Database::all(
            "SELECT o.client_id, c.payment_terms_days, SUM(o.total - o.paid) amount
             FROM orders o JOIN clients c ON c.id = o.client_id
             WHERE o.on_account = 1 AND o.invoice_id IS NULL AND o.status <> 'annule' AND DATE(o.created_at) BETWEEN ? AND ?
             GROUP BY o.client_id, c.payment_terms_days HAVING amount > 0",
            [$start, $end]
        );
        $count = 0;
        foreach ($rows as $r) {
            Database::transaction(function () use ($r, $start, $end): void {
                $number = Numbering::next('invoice', 'FA-%d-%05d');
                $due = date('Y-m-d', strtotime($end . ' +' . (int)$r['payment_terms_days'] . ' days'));
                $id = Database::insert('invoices', [
                    'number'       => $number,
                    'client_id'    => $r['client_id'],
                    'period_start' => $start,
                    'period_end'   => $end,
                    'total'        => (int)$r['amount'],
                    'paid'         => 0,
                    'due_date'     => $due,
                    'status'       => 'emise',
                    'created_at'   => now(),
                ]);
                Database::run(
                    "UPDATE orders SET invoice_id = ? WHERE client_id = ? AND on_account = 1 AND invoice_id IS NULL AND status <> 'annule' AND DATE(created_at) BETWEEN ? AND ?",
                    [$id, $r['client_id'], $start, $end]
                );
                Notifier::queue((int)$r['client_id'], "Pressing : facture {$number} de " . money($r['amount'], true) . ' émise, échéance le ' . date('d/m/Y', strtotime($due)) . '.', 'email');
                Audit::log('invoice.create', 'invoices', $id);
            });
            $count++;
        }
        return $count;
    }

    /** Balance âgée par client. Les commandes en compte non facturées sont classées "non échu". */
    public function aging(): array
    {
        $clients = [];
        $rows = Database::all(
            "SELECT c.id, c.name, c.phone, c.credit_limit,
                SUM(i.total) billed, SUM(i.paid) paid,
                SUM(CASE WHEN i.due_date >= CURDATE() THEN i.total - i.paid ELSE 0 END) b0,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30 THEN i.total - i.paid ELSE 0 END) b30,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN i.total - i.paid ELSE 0 END) b60,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN i.total - i.paid ELSE 0 END) b90,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) > 90 THEN i.total - i.paid ELSE 0 END) b90p,
                MIN(i.due_date) oldest_due
             FROM clients c JOIN invoices i ON i.client_id = c.id AND i.status <> 'payee'
             GROUP BY c.id, c.name, c.phone, c.credit_limit"
        );
        foreach ($rows as $r) {
            foreach (['id', 'credit_limit', 'billed', 'paid', 'b0', 'b30', 'b60', 'b90', 'b90p'] as $k) {
                $r[$k] = (int)$r[$k];
            }
            $clients[$r['id']] = $r;
        }
        $pending = Database::all(
            "SELECT c.id, c.name, c.phone, c.credit_limit, SUM(o.total - o.paid) amount
             FROM orders o JOIN clients c ON c.id = o.client_id
             WHERE o.on_account = 1 AND o.invoice_id IS NULL AND o.status <> 'annule'
             GROUP BY c.id, c.name, c.phone, c.credit_limit"
        );
        foreach ($pending as $p) {
            $id = (int)$p['id'];
            $clients[$id] ??= ['id' => $id, 'name' => $p['name'], 'phone' => $p['phone'], 'credit_limit' => (int)$p['credit_limit'], 'billed' => 0, 'paid' => 0, 'b0' => 0, 'b30' => 0, 'b60' => 0, 'b90' => 0, 'b90p' => 0, 'oldest_due' => null];
            $clients[$id]['b0'] += (int)$p['amount'];
        }
        foreach ($clients as $id => &$c) {
            $c['balance'] = $c['b0'] + $c['b30'] + $c['b60'] + $c['b90'] + $c['b90p'];
            $c['days_late'] = $c['oldest_due'] && $c['oldest_due'] < date('Y-m-d') ? (int)((time() - strtotime($c['oldest_due'])) / 86400) : 0;
            $c['last_reminder'] = Database::one('SELECT channel, created_at FROM reminders WHERE client_id = ? ORDER BY created_at DESC LIMIT 1', [$id]);
            $c['due_today'] = $c['days_late'] > 0 && (!$c['last_reminder'] || strtotime($c['last_reminder']['created_at']) < strtotime('-7 days'));
        }
        unset($c);
        uasort($clients, fn($a, $b) => $b['balance'] <=> $a['balance']);
        return array_values(array_filter($clients, fn($c) => $c['balance'] > 0));
    }

    public function remind(int $clientId, string $channel, string $note): void
    {
        $c = Database::one('SELECT * FROM clients WHERE id = ?', [$clientId]) ?? throw new \DomainException('Client introuvable.');
        $balance = ClientService::outstanding($clientId);
        Database::insert('reminders', ['client_id' => $clientId, 'channel' => $channel, 'note' => $note ?: null, 'user_id' => Auth::id() ?: null, 'created_at' => now()]);
        if (in_array($channel, ['sms', 'whatsapp', 'email'], true)) {
            Notifier::queue($clientId, "Pressing : relance — votre solde est de " . money($balance, true) . '. Merci de procéder au règlement. ' . $note, $channel);
        }
        Audit::log('receivable.remind', 'clients', $clientId, ['channel' => $channel]);
    }
}
