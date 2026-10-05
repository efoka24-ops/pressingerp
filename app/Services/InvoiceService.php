<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\PaymentMethod;

/**
 * Facturation des clients en compte. Les prix des commandes sont TTC (D5) : la facture ventile HT, TVA et TTC au taux en vigueur,
 * porte le NIU du vendeur et du client, et sa numérotation est continue par agence et par année (FA-CODE-AAAA-00001).
 * Une erreur de facturation se corrige par un avoir (AV-…), jamais en modifiant ni supprimant la facture.
 */
final class InvoiceService
{
    /** @var list<string> clients non facturés au dernier calcul, avec la raison */
    public array $skipped = [];

    /** @return array{0:int,1:int} [HT, TVA] pour un montant TTC. Le HT est arrondi, la TVA est le complément : HT + TVA = TTC. */
    public static function splitVat(int $ttc, ?float $rate = null): array
    {
        $rate ??= (float)str_replace(',', '.', (string)SettingsService::get('tax.vat_rate', '19.25'));
        $ht = (int)round($ttc / (1 + $rate / 100));
        return [$ht, $ttc - $ht];
    }

    /** Facturation mensuelle groupée des commandes "en compte" d'un mois (AAAA-MM), une facture par client et par agence. */
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
        $this->skipped = [];
        $requireNiu = (bool)SettingsService::get('invoice.require_client_niu', 0);
        $rows = Database::all(
            "SELECT o.client_id, o.agency_id, c.name, c.type, c.niu, c.payment_terms_days, SUM(o.total - o.paid) amount
             FROM orders o JOIN clients c ON c.id = o.client_id
             WHERE o.on_account = 1 AND o.invoice_id IS NULL AND o.status <> 'annule' AND DATE(o.created_at) BETWEEN ? AND ?
             GROUP BY o.client_id, o.agency_id, c.name, c.type, c.niu, c.payment_terms_days HAVING amount > 0",
            [$start, $end]
        );
        $count = 0;
        foreach ($rows as $r) {
            if ($requireNiu && $r['type'] === 'pro' && trim((string)$r['niu']) === '') {
                $this->skipped[] = $r['name'] . ' : NIU du client manquant';
                continue;
            }
            Database::transaction(function () use ($r, $start, $end): void {
                $code = (string)Database::value('SELECT code FROM agencies WHERE id = ?', [$r['agency_id']]);
                $number = Numbering::next('invoice.' . $r['agency_id'], 'FA-' . $code . '-%d-%05d');
                $due = date('Y-m-d', strtotime($end . ' +' . (int)$r['payment_terms_days'] . ' days'));
                $rate = (float)str_replace(',', '.', (string)SettingsService::get('tax.vat_rate', '19.25'));
                [$ht, $vat] = self::splitVat((int)$r['amount'], $rate);
                $id = Database::insert('invoices', [
                    'number'       => $number,
                    'client_id'    => $r['client_id'],
                    'agency_id'    => $r['agency_id'],
                    'kind'         => 'facture',
                    'period_start' => $start,
                    'period_end'   => $end,
                    'total'        => (int)$r['amount'],
                    'subtotal'     => $ht,
                    'vat_rate'     => $rate,
                    'vat_amount'   => $vat,
                    'seller_name'  => (string)SettingsService::get('company.name', 'Pressing'),
                    'seller_niu'   => (string)SettingsService::get('company.niu', '') ?: null,
                    'client_niu'   => trim((string)$r['niu']) ?: null,
                    'paid'         => 0,
                    'due_date'     => $due,
                    'status'       => 'emise',
                    'created_by'   => Auth::id() ?: null,
                    'created_at'   => now(),
                ]);
                Database::run(
                    "UPDATE orders SET invoice_id = ? WHERE client_id = ? AND agency_id = ? AND on_account = 1 AND invoice_id IS NULL AND status <> 'annule' AND DATE(created_at) BETWEEN ? AND ?",
                    [$id, $r['client_id'], $r['agency_id'], $start, $end]
                );
                Notifier::queue((int)$r['client_id'], "Pressing : facture {$number} de " . money($r['amount'], true) . ' émise, échéance le ' . date('d/m/Y', strtotime($due)) . '.', 'email');
                Audit::log('invoice.create', 'invoices', $id);
            });
            $count++;
        }
        return $count;
    }

    /**
     * Avoir : réduit une facture émise (erreur, geste commercial) sans la modifier en cachette.
     * Réservé aux responsables (autorisation tracée), motif obligatoire, jamais au-delà du reste dû.
     * L'avoir est un document à part (AV-…), à numérotation continue, qui référence la facture.
     * @return int identifiant de l'avoir
     */
    public function creditNote(int $invoiceId, int $amount, string $reason, array $authoriser): int
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 8) {
            throw new \DomainException('Motif détaillé obligatoire (8 caractères minimum).');
        }
        if ($amount <= 0) {
            throw new \DomainException('Montant d\'avoir invalide.');
        }
        return Database::transaction(function () use ($invoiceId, $amount, $reason, $authoriser): int {
            $i = Database::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId]) ?? throw new \DomainException('Facture introuvable.');
            if ($i['kind'] !== 'facture') {
                throw new \DomainException('Un avoir ne peut porter que sur une facture.');
            }
            $due = (int)$i['total'] - (int)$i['paid'];
            if ($amount > $due) {
                throw new \DomainException('Avoir supérieur au reste dû (' . money($due, true) . ') : pour la part déjà payée, remboursez d\'abord le client.');
            }
            $new = (int)$i['total'] - $amount;
            [$ht, $vat] = self::splitVat($new, (float)$i['vat_rate']);
            $status = $new <= (int)$i['paid'] ? 'payee' : ((int)$i['paid'] > 0 ? 'partielle' : 'emise');
            Database::update('invoices', ['total' => $new, 'subtotal' => $ht, 'vat_amount' => $vat, 'credited' => (int)$i['credited'] + $amount, 'status' => $status], 'id = :id', ['id' => $i['id']]);
            $code = (string)Database::value('SELECT code FROM agencies WHERE id = ?', [$i['agency_id']]) ?: 'XX';
            [$cht, $cvat] = self::splitVat($amount, (float)$i['vat_rate']);
            $id = Database::insert('invoices', [
                'number' => Numbering::next('creditnote.' . (int)$i['agency_id'], 'AV-' . $code . '-%d-%05d'), 'client_id' => $i['client_id'], 'agency_id' => $i['agency_id'], 'kind' => 'avoir',
                'ref_invoice_id' => $i['id'], 'period_start' => $i['period_start'], 'period_end' => $i['period_end'], 'total' => $amount, 'subtotal' => $cht, 'vat_rate' => $i['vat_rate'], 'vat_amount' => $cvat,
                'paid' => $amount, 'due_date' => date('Y-m-d'), 'status' => 'payee', 'seller_name' => $i['seller_name'], 'seller_niu' => $i['seller_niu'], 'client_niu' => $i['client_niu'],
                'reason' => mb_substr($reason, 0, 255), 'created_by' => Auth::id() ?: null, 'created_at' => now(),
            ]);
            Audit::log('invoice.credit_note', 'invoices', (int)$i['id'], ['credit_note' => $id, 'authorised_by' => (int)$authoriser['id'], 'requested_by' => Auth::id()], ['total' => (int)$i['total']], ['total' => $new], $reason);
            return $id;
        });
    }

    /**
     * Rapprochement d'un règlement (SE17) : un seul versement du client est ventilé sur ses factures ouvertes, de la plus ancienne
     * échéance à la plus récente, sous un seul reçu. Un versement supérieur à ce qui est dû est refusé (pas d'avance non affectée).
     * @return list<array{invoice:string,amount:int}>
     */
    public function settle(int $clientId, int $amount, PaymentMethod $method, ?string $reference = null): array
    {
        if ($amount <= 0) {
            throw new \DomainException('Montant invalide.');
        }
        return Database::transaction(function () use ($clientId, $amount, $method, $reference): array {
            $open = Database::all("SELECT id, number, total, paid FROM invoices WHERE client_id = ? AND kind = 'facture' AND status <> 'payee' ORDER BY due_date, id FOR UPDATE", [$clientId]);
            $due = array_sum(array_map(fn($i) => (int)$i['total'] - (int)$i['paid'], $open));
            if ($due <= 0) {
                throw new \DomainException('Ce client n\'a aucune facture à régler.');
            }
            if ($amount > $due) {
                throw new \DomainException('Versement supérieur au total dû (' . money($due, true) . ').');
            }
            $receipt = Numbering::next('receipt', 'RC-%d-%06d');
            $group = 'AL-' . strtoupper(bin2hex(random_bytes(4)));
            $left = $amount;
            $out = [];
            foreach ($open as $i) {
                if ($left <= 0) {
                    break;
                }
                $part = min($left, (int)$i['total'] - (int)$i['paid']);
                (new PaymentService())->record($clientId, $method, $part, invoiceId: (int)$i['id'], reference: $reference, splitGroup: $group, receiptNo: $receipt);
                $out[] = ['invoice' => $i['number'], 'amount' => $part];
                $left -= $part;
            }
            Audit::log('invoice.settle', 'clients', $clientId, ['amount' => $amount, 'invoices' => count($out), 'receipt' => $receipt]);
            return $out;
        });
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
             FROM clients c JOIN invoices i ON i.client_id = c.id AND i.status <> 'payee' AND i.kind = 'facture'
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
