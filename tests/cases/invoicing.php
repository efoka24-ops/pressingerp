<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Domain\PaymentMethod;
use App\Services\ClientService;
use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Services\QuoteService;
use App\Services\SettingsService;

/** Client pro avec commandes en compte le mois dernier. @return array{client:int,agency:int,orders:list<int>} */
function fx_account(int $agency, array $totals, array $client = []): array
{
    $c = Database::insert('clients', array_merge(['code' => 'T' . bin2hex(random_bytes(5)), 'type' => 'pro', 'name' => 'Hôtel Test ' . bin2hex(random_bytes(2)), 'phone' => '+2376' . random_int(10000000, 99999999), 'payment_terms_days' => 30], $client));
    $orders = [];
    foreach ($totals as $t) {
        $o = fx_order($agency, $c, $t);
        Database::update('orders', ['on_account' => 1, 'created_at' => date('Y-m-10 10:00:00', strtotime('first day of last month'))], 'id = :id', ['id' => $o]);
        $orders[] = $o;
    }
    return ['client' => $c, 'agency' => $agency, 'orders' => $orders];
}

function last_month(): string
{
    return date('Y-m', strtotime('first day of last month'));
}

function fx_invoice_row(int $client, int $total, string $due, int $paid = 0): int
{
    return Database::insert('invoices', [
        'number' => 'T-' . bin2hex(random_bytes(6)), 'client_id' => $client, 'agency_id' => null, 'period_start' => date('Y-m-01'), 'period_end' => date('Y-m-t'), 'total' => $total, 'paid' => $paid,
        'due_date' => $due, 'status' => $paid >= $total ? 'payee' : ($paid > 0 ? 'partielle' : 'emise'), 'vat_rate' => 19.25, 'subtotal' => (int)round($total / 1.1925), 'vat_amount' => $total - (int)round($total / 1.1925), 'created_at' => now(),
    ]);
}

test('TVA : HT + TVA = TTC pour tout montant, au taux paramétré', function () {
    [$ht, $vat] = InvoiceService::splitVat(11925, 19.25);
    same(10000, $ht);
    same(1925, $vat);
    for ($ttc = 1; $ttc <= 3000; $ttc += 7) {
        [$h, $v] = InvoiceService::splitVat($ttc, 19.25);
        ok($h + $v === $ttc && $h >= 0 && $v >= 0, "TTC $ttc");
    }
    [$h0, $v0] = InvoiceService::splitVat(5000, 0.0);
    same([5000, 0], [$h0, $v0], 'taux nul');
});

test('facture : TVA ventilée, NIU du vendeur et du client, numérotation continue par agence et par année', function () {
    Auth::actAs(fx_user('admin', fx_agency()));
    SettingsService::set('company.niu', 'M012345678901X', 'Test NIU entreprise');
    $a1 = fx_agency('FA');
    $a2 = fx_agency('FB');
    $x = fx_account($a1, [5000, 7000], ['niu' => 'P987654321098Z']);
    $y = fx_account($a1, [3000]);
    $z = fx_account($a2, [4000]);
    $svc = new InvoiceService();
    $svc->generate(last_month());
    $inv = Database::one('SELECT * FROM invoices WHERE client_id = ? AND kind = ?', [$x['client'], 'facture']);
    same(12000, (int)$inv['total']);
    same(10063, (int)$inv['subtotal'], 'HT arrondi');
    same(12000, (int)$inv['subtotal'] + (int)$inv['vat_amount']);
    same('19.25', (string)$inv['vat_rate']);
    same('M012345678901X', $inv['seller_niu']);
    same('P987654321098Z', $inv['client_niu']);
    $code1 = Database::value('SELECT code FROM agencies WHERE id = ?', [$a1]);
    ok(preg_match('/^FA-' . $code1 . '-' . date('Y') . '-\d{5}$/', $inv['number']) === 1, $inv['number']);
    $invY = Database::one('SELECT * FROM invoices WHERE client_id = ?', [$y['client']]);
    $n1 = (int)substr($inv['number'], -5);
    $n2 = (int)substr($invY['number'], -5);
    same(1, abs($n1 - $n2), 'numéros consécutifs dans la même agence');
    $invZ = Database::one('SELECT * FROM invoices WHERE client_id = ?', [$z['client']]);
    same(1, (int)substr($invZ['number'], -5), 'compteur propre à l\'autre agence');
    same((int)$inv['id'], (int)Database::value('SELECT invoice_id FROM orders WHERE id = ?', [$x['orders'][0]]));
    $again = $svc->generate(last_month());
    same(0, (int)Database::value('SELECT COUNT(*) FROM invoices WHERE client_id = ? AND kind = ?', [$x['client'], 'facture']) - 1, 'idempotent : pas de seconde facture');
});

test('facture : un même client commandant dans deux agences reçoit une facture par agence', function () {
    Auth::actAs(fx_user('admin', fx_agency()));
    $a1 = fx_agency('FC');
    $a2 = fx_agency('FD');
    $x = fx_account($a1, [5000]);
    $o = fx_order($a2, $x['client'], 2000);
    Database::update('orders', ['on_account' => 1, 'created_at' => date('Y-m-12 10:00:00', strtotime('first day of last month'))], 'id = :id', ['id' => $o]);
    (new InvoiceService())->generate(last_month());
    same(2, (int)Database::value("SELECT COUNT(*) FROM invoices WHERE client_id = ? AND kind = 'facture'", [$x['client']]));
    same([$a1, $a2], array_map('intval', array_column(Database::all('SELECT agency_id FROM invoices WHERE client_id = ? ORDER BY agency_id', [$x['client']]), 'agency_id')));
});

test('facture : NIU du client pro exigé quand le paramètre est activé, sinon facturé quand même', function () {
    Auth::actAs(fx_user('admin', fx_agency()));
    $agency = fx_agency('FE');
    $with = fx_account($agency, [5000], ['niu' => 'P111111111111A']);
    $without = fx_account($agency, [6000]);
    SettingsService::set('invoice.require_client_niu', '1', 'Test NIU obligatoire');
    $svc = new InvoiceService();
    $svc->generate(last_month());
    same(1, (int)Database::value('SELECT COUNT(*) FROM invoices WHERE client_id = ?', [$with['client']]));
    same(0, (int)Database::value('SELECT COUNT(*) FROM invoices WHERE client_id = ?', [$without['client']]), 'pro sans NIU non facturé');
    ok(count(array_filter($svc->skipped, fn($s) => str_contains($s, 'NIU'))) >= 1);
    SettingsService::set('invoice.require_client_niu', '0', 'Retour au réglage normal');
    (new InvoiceService())->generate(last_month());
    same(1, (int)Database::value('SELECT COUNT(*) FROM invoices WHERE client_id = ?', [$without['client']]), 'facturé une fois l\'exigence levée');
});

test('avoir : motif et montant contrôlés, facture réduite sans être modifiée en cachette, document numéroté', function () {
    $admin = fx_user('admin', fx_agency());
    Auth::actAs($admin);
    $agency = fx_agency('FF');
    $x = fx_account($agency, [10000]);
    (new InvoiceService())->generate(last_month());
    $inv = Database::one('SELECT * FROM invoices WHERE client_id = ?', [$x['client']]);
    $svc = new InvoiceService();
    throws(fn() => $svc->creditNote((int)$inv['id'], 2000, 'court', $admin), 'Motif');
    throws(fn() => $svc->creditNote((int)$inv['id'], 0, 'Erreur de tarif appliqué', $admin), 'invalide');
    throws(fn() => $svc->creditNote((int)$inv['id'], 10001, 'Erreur de tarif appliqué', $admin), 'supérieur au reste dû');
    $cn = $svc->creditNote((int)$inv['id'], 2500, 'Erreur de tarif appliqué', $admin);
    $after = Database::one('SELECT * FROM invoices WHERE id = ?', [$inv['id']]);
    same(7500, (int)$after['total']);
    same(2500, (int)$after['credited']);
    same(7500, (int)$after['subtotal'] + (int)$after['vat_amount']);
    $credit = Database::one('SELECT * FROM invoices WHERE id = ?', [$cn]);
    same('avoir', $credit['kind']);
    same((int)$inv['id'], (int)$credit['ref_invoice_id']);
    ok(str_starts_with($credit['number'], 'AV-'), $credit['number']);
    same('payee', $credit['status'], 'l\'avoir ne compte pas dans les créances');
    same(7500, ClientService::outstanding($x['client']));
    $aging = array_values(array_filter((new InvoiceService())->aging(), fn($r) => $r['id'] === $x['client']));
    same(7500, $aging[0]['balance']);
    same(1, (int)Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'invoice.credit_note' AND entity_id = ?", [$inv['id']]));
    $cn2 = $svc->creditNote((int)$inv['id'], 7500, 'Facture annulée, commande doublon', $admin);
    same('payee', Database::value('SELECT status FROM invoices WHERE id = ?', [$inv['id']]), 'facture soldée par avoirs');
    same(0, ClientService::outstanding($x['client']));
    throws(fn() => $svc->creditNote($cn2, 100, 'Avoir sur un avoir interdit', $admin), 'facture');
    ok($credit['number'] !== Database::value('SELECT number FROM invoices WHERE id = ?', [$cn2]), 'numéros d\'avoir distincts');
});

test('avoir : on ne peut pas créditer ce qui est déjà payé', function () {
    $admin = fx_user('admin', fx_agency());
    Auth::actAs($admin);
    $agency = fx_agency('FG');
    $x = fx_account($agency, [10000]);
    (new InvoiceService())->generate(last_month());
    $inv = Database::one('SELECT * FROM invoices WHERE client_id = ?', [$x['client']]);
    (new InvoiceService())->settle($x['client'], 6000, PaymentMethod::Virement, 'VIR-1');
    throws(fn() => (new InvoiceService())->creditNote((int)$inv['id'], 4001, 'Dépasse le reste dû', $admin), 'reste dû');
    (new InvoiceService())->creditNote((int)$inv['id'], 4000, 'Reste dû annulé, geste commercial', $admin);
    same('payee', Database::value('SELECT status FROM invoices WHERE id = ?', [$inv['id']]));
    same(6000, (int)Database::value('SELECT total FROM invoices WHERE id = ?', [$inv['id']]));
});

test('rapprochement SE17 : un versement est réparti de la facture la plus ancienne à la plus récente, sous un seul reçu', function () {
    Auth::actAs(fx_user('admin', fx_agency()));
    $c = fx_client();
    $old = fx_invoice_row($c, 5000, date('Y-m-d', strtotime('-40 days')));
    $mid = fx_invoice_row($c, 4000, date('Y-m-d', strtotime('-10 days')));
    $new = fx_invoice_row($c, 3000, date('Y-m-d', strtotime('+20 days')));
    $svc = new InvoiceService();
    throws(fn() => $svc->settle($c, 0, PaymentMethod::Virement), 'invalide');
    throws(fn() => $svc->settle($c, 12001, PaymentMethod::Virement), 'supérieur au total dû');
    $parts = $svc->settle($c, 6000, PaymentMethod::Virement, 'VIR-2');
    same(2, count($parts));
    same(5000, $parts[0]['amount']);
    same(1000, $parts[1]['amount']);
    $row = fn(int $id) => Database::one('SELECT paid, status FROM invoices WHERE id = ?', [$id]);
    same(['5000', 'payee'], array_values(array_map('strval', $row($old))));
    same(['1000', 'partielle'], array_values(array_map('strval', $row($mid))));
    same(['0', 'emise'], array_values(array_map('strval', $row($new))));
    $receipts = array_unique(array_column(Database::all('SELECT receipt_no FROM payments WHERE client_id = ?', [$c]), 'receipt_no'));
    same(1, count($receipts), 'un seul reçu pour le versement');
    $svc->settle($c, 6000, PaymentMethod::Virement, 'VIR-3');
    same('payee', $row($new)['status']);
    throws(fn() => $svc->settle($c, 100, PaymentMethod::Virement), 'aucune facture');
});

test('balance âgée : bornes des tranches (échéance +0, 30/31, 60/61, 90/91 jours)', function () {
    Auth::actAs(fx_user('admin', fx_agency()));
    $cases = ['0' => ['b0', 0], '-1' => ['b30', 1], '-30' => ['b30', 30], '-31' => ['b60', 31], '-60' => ['b60', 60], '-61' => ['b90', 61], '-90' => ['b90', 90], '-91' => ['b90p', 91], '+5' => ['b0', -5]];
    $ids = [];
    foreach ($cases as $offset => [$bucket, $late]) {
        $c = fx_client();
        fx_invoice_row($c, 1000, date('Y-m-d', strtotime("$offset days")));
        $ids[$c] = [$bucket, $late];
    }
    $rows = [];
    foreach ((new InvoiceService())->aging() as $r) {
        $rows[$r['id']] = $r;
    }
    foreach ($ids as $client => [$bucket, $late]) {
        foreach (['b0', 'b30', 'b60', 'b90', 'b90p'] as $b) {
            same($b === $bucket ? 1000 : 0, $rows[$client][$b], "échéance dépassée de $late j : tranche $bucket, colonne $b");
        }
    }
});

test('plafond RG16 : au-delà du plafond la commande est bloquée, sauf dérogation motivée d\'un responsable, tracée', function () {
    $agency = fx_agency('FH');
    $counter = fx_user('comptoir', $agency);
    $manager = fx_user('manager', $agency);
    Auth::actAs($counter);
    $client = fx_client();
    Database::update('clients', ['type' => 'pro', 'credit_limit' => 10000, 'name' => 'Société Test Pro'], 'id = :id', ['id' => $client]);
    Database::insert('contracts', ['client_id' => $client, 'start_date' => date('Y-m-d', strtotime('-10 days')), 'end_date' => date('Y-m-d', strtotime('+300 days')), 'active' => 1, 'discount_pct' => 0]);
    $article = fx_article('Costume', 6000);
    $in = ['client_id' => $client, 'lines' => [['article_id' => $article, 'qty' => 1]]];
    $svc = new OrderService();
    $first = $svc->create($in);
    same(1, (int)Database::value('SELECT on_account FROM orders WHERE id = ?', [$first]), 'commande en compte');
    $e = throws(fn() => $svc->create($in), 'commande bloquée');
    same(1, (int)Database::value('SELECT COUNT(*) FROM orders WHERE client_id = ?', [$client]), 'rien n\'a été créé');
    throws(fn() => $svc->create($in, [], ['credit_override' => ['reason' => 'urgent', 'authoriser' => $manager]]), 'Motif de la dérogation');
    $second = $svc->create($in, [], ['credit_override' => ['reason' => 'Client historique, règlement annoncé vendredi', 'authoriser' => $manager]]);
    $ov = Database::one('SELECT * FROM credit_overrides WHERE order_id = ?', [$second]);
    same((int)$manager['id'], (int)$ov['authorised_by']);
    same((int)$counter['id'], (int)$ov['requested_by']);
    same(10000, (int)$ov['credit_limit']);
    same(6000, (int)$ov['outstanding']);
    ok(Database::value("SELECT id FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL", ['cro:' . $second]) !== null, 'alerte à la direction');
    same(1, (int)Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'order.credit_override' AND entity_id = ?", [$second]));
});

test('plafond : un responsable connecté n\'est plus dispensé de motif, et le blocage peut être levé par paramètre', function () {
    $agency = fx_agency('FI');
    $manager = fx_user('manager', $agency);
    Auth::actAs($manager);
    $client = fx_client();
    Database::update('clients', ['type' => 'pro', 'credit_limit' => 5000, 'name' => 'Société Autre Pro'], 'id = :id', ['id' => $client]);
    Database::insert('contracts', ['client_id' => $client, 'start_date' => date('Y-m-d', strtotime('-10 days')), 'end_date' => date('Y-m-d', strtotime('+300 days')), 'active' => 1, 'discount_pct' => 0]);
    $article = fx_article('Manteau', 6000);
    $in = ['client_id' => $client, 'lines' => [['article_id' => $article, 'qty' => 1]]];
    throws(fn() => (new OrderService())->create($in), 'commande bloquée');
    Auth::actAs(fx_user('admin', $agency));
    SettingsService::set('credit.block_over_limit', '0', 'Test : blocage levé');
    Auth::actAs($manager);
    ok((new OrderService())->create($in) > 0, 'blocage désactivé par l\'administrateur');
    Auth::actAs(fx_user('admin', $agency));
    SettingsService::set('credit.block_over_limit', '1', 'Test : blocage rétabli');
});

test('devis : établi aux tarifs du client, valable N jours, réponse du client, expiration', function () {
    $agency = fx_agency('FJ');
    Auth::actAs(fx_user('commercial', $agency));
    $client = fx_client();
    $article = fx_article('Rideau', 4000);
    $svc = new QuoteService();
    throws(fn() => $svc->create($client, 'standard', []), 'au moins un article');
    throws(fn() => $svc->create($client, 'standard', [['article_id' => $article, 'qty' => 1]], 0), 'Validité');
    $id = $svc->create($client, 'standard', [['article_id' => $article, 'qty' => 2]], 10, 'Pour le salon');
    $q = Database::one('SELECT * FROM quotes WHERE id = ?', [$id]);
    same(8000, (int)$q['total']);
    same('envoye', $q['status']);
    ok(preg_match('/^DV-.+-' . date('Y') . '-\d{5}$/', $q['number']) === 1, $q['number']);
    same(2, (int)Database::value('SELECT COUNT(*) FROM quote_lines WHERE quote_id = ?', [$id]));
    throws(fn() => $svc->decide($id, 'refuse', ''), 'raison');
    $svc->decide($id, 'accepte');
    same('accepte', Database::value('SELECT status FROM quotes WHERE id = ?', [$id]));
    throws(fn() => $svc->decide($id, 'refuse', 'Trop cher'), 'déjà reçu');
    $old = $svc->create($client, 'standard', [['article_id' => $article, 'qty' => 1]], 5);
    Database::update('quotes', ['valid_until' => date('Y-m-d', strtotime('-1 day'))], 'id = :id', ['id' => $old]);
    throws(fn() => $svc->decide($old, 'accepte'), 'expiré');
    same('expire', Database::value('SELECT status FROM quotes WHERE id = ?', [$old]));
});

test('pages : factures avec TVA et NIU, avoir, devis, recouvrement avec versement', function () {
    $admin = fx_user('admin', fx_agency());
    Auth::actAs($admin);
    $agency = fx_agency('FK');
    $x = fx_account($agency, [9000], ['niu' => 'P222222222222B']);
    (new InvoiceService())->generate(last_month());
    $inv = Database::one('SELECT * FROM invoices WHERE client_id = ?', [$x['client']]);
    $html = render_page('/commercial/factures/' . $inv['id']);
    ok(str_contains($html, 'Total HT') && str_contains($html, 'TVA') && str_contains($html, 'Total TTC') && str_contains($html, 'P222222222222B') && str_contains($html, 'Émettre un avoir'));
    $cn = (new InvoiceService())->creditNote((int)$inv['id'], 1000, 'Erreur sur un article', $admin);
    ok(str_contains(render_page('/commercial/factures/' . $cn), 'Avoir sur la facture'));
    ok(str_contains(render_page('/commercial/factures'), 'Avoir'));
    ok(str_contains(render_page('/recouvrement'), 'Encaisser un versement'));
    $article = fx_article('Drap', 3000);
    $q = (new QuoteService())->create($x['client'], 'standard', [['article_id' => $article, 'qty' => 1]]);
    ok(str_contains(render_page('/commercial/devis'), 'DV-') && str_contains(render_page('/commercial/devis/nouveau'), 'Nouveau devis') && str_contains(render_page('/commercial/devis/' . $q), 'Total TTC'));
});
