<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Domain\PaymentMethod;
use App\Domain\ServiceLevel;
use App\Services\Audit;
use App\Services\Authorizer;
use App\Services\QuoteService;
use App\Services\ClientService;
use App\Services\InvoiceService;
use App\Services\PaymentService;

final class CommercialController extends Controller
{
    public function contracts(): void
    {
        $lastMonth = date('Y-m-01', strtotime('first day of last month'));
        $thisMonth = date('Y-m-01');
        $contracts = Database::all(
            "SELECT k.*, c.name client, c.credit_limit,
                    (SELECT COUNT(*) FROM garments g JOIN orders o ON o.id = g.order_id WHERE o.client_id = k.client_id AND o.created_at > NOW() - INTERVAL 30 DAY AND o.status <> 'annule') volume,
                    (SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.client_id = k.client_id AND o.created_at >= :lm AND o.created_at < :tm AND o.status <> 'annule') revenue_last
             FROM contracts k JOIN clients c ON c.id = k.client_id
             WHERE k.active = 1 ORDER BY k.end_date",
            ['lm' => $lastMonth, 'tm' => $thisMonth]
        );
        foreach ($contracts as &$k) {
            $k['outstanding'] = ClientService::outstanding((int)$k['client_id']);
        }
        unset($k);
        $this->view('commercial/contracts', [
            'title'     => 'Commercial',
            'contracts' => $contracts,
            'recurring' => array_sum(array_column($contracts, 'revenue_last')),
            'renewals'  => count(array_filter($contracts, fn($k) => strtotime($k['end_date']) < strtotime('+60 days'))),
            'pros'      => Database::all("SELECT id, name FROM clients WHERE type = 'pro' AND id NOT IN (SELECT client_id FROM contracts WHERE active = 1) ORDER BY name"),
            'lastMonth' => substr($lastMonth, 0, 7),
            'unbilled'  => (int)Database::value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE on_account = 1 AND invoice_id IS NULL AND status <> 'annule' AND created_at < ?", [$thisMonth]),
        ]);
    }

    public function storeContract(): void
    {
        $req = $this->required(['client_id' => 'Client', 'start_date' => 'Début', 'end_date' => 'Fin']);
        if ($req['end_date'] <= $req['start_date']) {
            $this->fail('La date de fin doit suivre la date de début.');
        }
        $id = Database::insert('contracts', [
            'client_id'       => (int)$req['client_id'],
            'sector'          => $this->str('sector') ?: null,
            'tariff_label'    => $this->str('tariff_label') ?: null,
            'discount_pct'    => min(60, max(0, (float)str_replace(',', '.', $this->str('discount_pct', '0')))),
            'pickup_schedule' => $this->str('pickup_schedule') ?: null,
            'start_date'      => $req['start_date'],
            'end_date'        => $req['end_date'],
            'active'          => 1,
        ]);
        if ($limit = $this->int('credit_limit')) {
            $before = Database::one('SELECT credit_limit, payment_terms_days FROM clients WHERE id = ?', [(int)$req['client_id']]);
            Audit::log('client.credit_limit', 'clients', (int)$req['client_id'], [], $before, ['credit_limit' => $limit, 'payment_terms_days' => max(0, $this->int('payment_terms_days', 30))], 'Contrat n° ' . $id);
            Database::update('clients', ['credit_limit' => $limit, 'payment_terms_days' => max(0, $this->int('payment_terms_days', 30))], 'id = :id', ['id' => (int)$req['client_id']]);
        }
        Audit::log('contract.create', 'contracts', $id);
        $this->ok('Contrat enregistré.', '/commercial');
    }

    public function endContract(string $id): void
    {
        Database::update('contracts', ['active' => 0, 'end_date' => date('Y-m-d')], 'id = :id', ['id' => (int)$id]);
        Audit::log('contract.end', 'contracts', (int)$id);
        $this->ok('Contrat résilié.', '/commercial');
    }

    public function invoices(): void
    {
        $status = in_array($this->str('statut'), ['emise', 'partielle', 'payee'], true) ? $this->str('statut') : '';
        $this->view('commercial/invoices', [
            'title'    => 'Factures',
            'status'   => $status,
            'invoices' => Database::all(
                'SELECT i.*, c.name client FROM invoices i JOIN clients c ON c.id = i.client_id' . ($status ? ' WHERE i.status = ?' : '') . ' ORDER BY i.created_at DESC LIMIT 200',
                $status ? [$status] : []
            ),
            'lastMonth' => date('Y-m', strtotime('first day of last month')),
        ]);
    }

    public function generate(): void
    {
        $svc = new InvoiceService();
        try {
            $n = $svc->generate($this->str('month'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $msg = $n ? "$n facture(s) générée(s)." : 'Aucune commande en compte à facturer sur ce mois.';
        if ($svc->skipped) {
            $msg .= ' Non facturés : ' . implode(' ; ', $svc->skipped) . '.';
        }
        $this->ok($msg, '/commercial/factures');
    }

    public function invoice(string $id): void
    {
        $i = Database::one('SELECT i.*, c.name client, c.address, c.email, c.phone FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.id = ?', [(int)$id])
            ?? throw new HttpException(404, 'Facture introuvable');
        $this->view('commercial/invoice', [
            'title'    => $i['number'],
            'i'        => $i,
            'agency'   => (string)Database::value('SELECT name FROM agencies WHERE id = ?', [$i['agency_id']]),
            'refNumber' => (string)Database::value('SELECT number FROM invoices WHERE id = ?', [$i['ref_invoice_id']]),
            'credits'  => Database::all("SELECT id, number, total, reason FROM invoices WHERE ref_invoice_id = ? AND kind = 'avoir' ORDER BY id", [$i['id']]),
            'orders'   => Database::all('SELECT o.*, (SELECT COUNT(*) FROM garments g WHERE g.order_id = o.id) pcs FROM orders o WHERE o.invoice_id = ? ORDER BY o.created_at', [$i['id']]),
            'payments' => Database::all('SELECT * FROM payments WHERE invoice_id = ? ORDER BY created_at', [$i['id']]),
            'methods'  => [PaymentMethod::Virement, PaymentMethod::Cheque, PaymentMethod::Especes, PaymentMethod::Orange, PaymentMethod::Mtn],
        ]);
    }

    public function payInvoice(string $id): void
    {
        $i = Database::one('SELECT * FROM invoices WHERE id = ?', [(int)$id]) ?? throw new HttpException(404);
        $method = PaymentMethod::tryFrom($this->str('method')) ?? $this->fail('Mode de paiement invalide.');
        try {
            (new PaymentService())->record((int)$i['client_id'], $method, $this->int('amount'), invoiceId: (int)$i['id'], reference: $this->str('reference') ?: null);
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Règlement enregistré.', '/commercial/factures/' . $i['id']);
    }

    public function creditNote(string $id): void
    {
        $i = Database::one('SELECT id, agency_id FROM invoices WHERE id = ?', [(int)$id]) ?? throw new HttpException(404);
        try {
            $authoriser = Authorizer::fromRequest((int)$i['agency_id'] ?: null);
            $cn = (new InvoiceService())->creditNote((int)$i['id'], $this->int('amount'), $this->str('reason'), $authoriser);
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Avoir émis.', '/commercial/factures/' . $cn);
    }

    /** Un versement du client réparti sur ses factures ouvertes, de la plus ancienne à la plus récente. */
    public function settle(): void
    {
        $method = PaymentMethod::tryFrom($this->str('method')) ?? $this->fail('Mode de paiement invalide.');
        try {
            $parts = (new InvoiceService())->settle($this->int('client_id'), $this->int('amount'), $method, $this->str('reference') ?: null);
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Versement réparti : ' . implode(', ', array_map(fn($p) => $p['invoice'] . ' ' . money($p['amount']), $parts)) . '.', '/recouvrement');
    }

    public function quotes(): void
    {
        QuoteService::expireOld();
        $this->view('commercial/quotes', ['title' => 'Devis', 'quotes' => Database::all('SELECT q.*, c.name client FROM quotes q JOIN clients c ON c.id = q.client_id ORDER BY q.id DESC LIMIT 200')]);
    }

    public function quoteForm(): void
    {
        $this->view('commercial/quote_form', [
            'title'    => 'Nouveau devis',
            'clients'  => Database::all('SELECT id, name, phone FROM clients ORDER BY name LIMIT 500'),
            'articles' => Database::all('SELECT id, name FROM articles WHERE active = 1 ORDER BY sort, name'),
            'levels'   => ServiceLevel::cases(),
        ]);
    }

    public function storeQuote(): void
    {
        try {
            $id = (new QuoteService())->create($this->int('client_id'), $this->str('service_level', 'standard'), (array)($_POST['lines'] ?? []), $this->int('valid_days', 15), $this->str('notes'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Devis établi.', '/commercial/devis/' . $id);
    }

    public function quote(string $id): void
    {
        $q = Database::one('SELECT q.*, c.name client, a.name agency FROM quotes q JOIN clients c ON c.id = q.client_id JOIN agencies a ON a.id = q.agency_id WHERE q.id = ?', [(int)$id]) ?? throw new HttpException(404, 'Devis introuvable');
        $this->view('commercial/quote', ['title' => $q['number'], 'q' => $q, 'lines' => Database::all('SELECT * FROM quote_lines WHERE quote_id = ? ORDER BY id', [$q['id']])]);
    }

    public function decideQuote(string $id): void
    {
        try {
            (new QuoteService())->decide((int)$id, $this->str('decision'), $this->str('note'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Réponse enregistrée.', '/commercial/devis/' . (int)$id);
    }

    /** État des créances imprimable (même calcul que l'écran de recouvrement). */
    public function receivablesDoc(): void
    {
        $rows = (new InvoiceService())->aging();
        $buckets = ['b0' => 'Non échu', 'b30' => '1–30 j', 'b60' => '31–60 j', 'b90' => '61–90 j', 'b90p' => '> 90 j'];
        $totals = [];
        foreach (array_keys($buckets) as $b) {
            $totals[$b] = array_sum(array_column($rows, $b));
        }
        $this->view('commercial/receivables_doc', ['title' => 'État des créances', 'rows' => $rows, 'buckets' => $buckets, 'totals' => $totals, 'total' => array_sum($totals)]);
    }

    public function receivables(): void
    {
        $rows = (new InvoiceService())->aging();
        $buckets = ['b0' => 'Non échu', 'b30' => '1–30 j', 'b60' => '31–60 j', 'b90' => '61–90 j', 'b90p' => '> 90 j'];
        $totals = [];
        foreach (array_keys($buckets) as $b) {
            $totals[$b] = array_sum(array_column($rows, $b));
        }
        $this->view('commercial/receivables', [
            'title'   => 'Recouvrement',
            'rows'    => $rows,
            'buckets' => $buckets,
            'totals'  => $totals,
            'total'   => array_sum($totals),
            'dueToday' => count(array_filter($rows, fn($r) => $r['due_today'])),
        ]);
    }

    public function remind(): void
    {
        $channel = in_array($this->str('channel'), ['sms', 'whatsapp', 'email', 'appel', 'visite'], true) ? $this->str('channel') : 'appel';
        try {
            (new InvoiceService())->remind($this->int('client_id'), $channel, $this->str('note'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Relance enregistrée.', '/recouvrement');
    }
}
