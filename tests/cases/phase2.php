<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Domain\ServiceLevel;
use App\Services\ClientService;
use App\Services\LabelService;
use App\Services\OrderService;
use App\Services\PricingMissing;
use App\Services\PricingService;
use App\Services\SettingsService;

function fx_article(string $name, int $legacyPrice = 0, bool $fragile = false): int
{
    return Database::insert('articles', ['name' => $name . ' ' . bin2hex(random_bytes(2)), 'price' => $legacyPrice, 'unit' => 'piece', 'fragile' => $fragile ? 1 : 0, 'sort' => 999, 'active' => 1]);
}

function fx_list(string $kind, array $extra = []): int
{
    return Database::insert('price_lists', array_merge(['kind' => $kind, 'name' => "Test $kind", 'active' => 1, 'created_at' => now()], $extra));
}

function fx_price(int $list, int $article, ?int $price): void
{
    Database::insert('price_items', ['list_id' => $list, 'article_id' => $article, 'price' => $price, 'reason' => 'test', 'created_at' => now()]);
}

// --- Clients identifiables ---------------------------------------------------------------------

test('client : nom complet et numéro valide exigés (pas de client anonyme)', function () {
    same(null, ClientService::nameError('Jean-Marc Mbarga', 'particulier'));
    ok(ClientService::nameError('Mbarga', 'particulier') !== null, 'un seul mot refusé');
    ok(ClientService::nameError('   ', 'particulier') !== null, 'nom vide refusé');
    ok(ClientService::nameError('J M', 'particulier') !== null, 'initiales refusées');
    same(null, ClientService::nameError('Hôtel Ibis', 'pro'));
    same(null, ClientService::phoneError(ClientService::normalizePhone('670 12 34 56')));
    same(null, ClientService::phoneError(ClientService::normalizePhone('222 33 44 55')), 'fixe camerounais');
    same(null, ClientService::phoneError('+33612345678'), 'numéro étranger avec indicatif');
    ok(ClientService::phoneError(ClientService::normalizePhone('12345')) !== null, 'trop court');
    ok(ClientService::phoneError(ClientService::normalizePhone('570123456')) !== null, '5… invalide au Cameroun');
});

test('client : deux fiches ne peuvent pas avoir le même numéro', function () {
    $phone = '+2376' . random_int(10000000, 99999999);
    Database::insert('clients', ['code' => 'T' . bin2hex(random_bytes(5)), 'name' => 'Premier Client', 'phone' => $phone]);
    throws(fn() => Database::insert('clients', ['code' => 'T' . bin2hex(random_bytes(5)), 'name' => 'Second Client', 'phone' => $phone]), 'Duplicate');
});

test('commande : refusée sans client, ou avec un client non identifiable', function () {
    Auth::actAs(fx_user('comptoir', fx_agency()));
    $article = fx_article('Chemise', 1000);
    $line = [['article_id' => $article, 'qty' => 1]];
    throws(fn() => (new OrderService())->create(['client_id' => 0, 'lines' => $line]), 'Sélectionnez un client');
    $bad = Database::insert('clients', ['code' => 'T' . bin2hex(random_bytes(5)), 'name' => 'Anonyme', 'phone' => '+2376' . random_int(10000000, 99999999)]);
    throws(fn() => (new OrderService())->create(['client_id' => $bad, 'lines' => $line]), 'non identifiable');
});

// --- Photo à la réception ------------------------------------------------------------------------

test('photo : fragile, endommagé ou de valeur => obligatoire, selon le seuil paramétrable', function () {
    same('article fragile', PricingService::photoReason(['fragile' => true, 'damages' => '', 'price' => 100], 50000));
    same('déjà endommagé', PricingService::photoReason(['fragile' => false, 'damages' => 'tache', 'price' => 100], 50000));
    ok(str_contains((string)PricingService::photoReason(['fragile' => false, 'damages' => '', 'price' => 60000], 50000), 'valeur'));
    same(null, PricingService::photoReason(['fragile' => false, 'damages' => '', 'price' => 49999], 50000));
    same(null, PricingService::photoReason(['fragile' => false, 'damages' => '', 'price' => 999999], 0), 'seuil 0 = désactivé');
});

test('photo : la commande est refusée côté serveur si une pièce de valeur n\'a pas de photo', function () {
    Auth::actAs(fx_user('comptoir', fx_agency()));
    SettingsService::set('photo.value_threshold', '50000', 'test');
    $robe = fx_article('Robe de gala', 80000);
    $client = fx_client();
    throws(fn() => (new OrderService())->create(['client_id' => $client, 'lines' => [['article_id' => $robe, 'qty' => 1]]]), 'Photo obligatoire');
});

// --- Tarification --------------------------------------------------------------------------------

test('tarifs : priorité contrat > agence > VIP > promotion > standard', function () {
    $agencyA = fx_agency();
    $agencyB = fx_agency();
    $a = fx_article('Costume');
    $std = (int)Database::value("SELECT id FROM price_lists WHERE kind = 'standard' LIMIT 1");
    fx_price($std, $a, 1000);
    $today = date('Y-m-d');
    $promo = fx_list('promo', ['valid_from' => $today, 'valid_to' => $today]);
    fx_price($promo, $a, 800);
    $vip = fx_list('vip');
    fx_price($vip, $a, 950);
    $agency = fx_list('agency', ['agency_id' => $agencyA]);
    fx_price($agency, $a, 900);

    $normal = fx_client();
    $vipClient = fx_client();
    Database::run('UPDATE clients SET is_vip = 1 WHERE id = ?', [$vipClient]);
    $biz = fx_client();
    $business = fx_list('business', ['client_id' => $biz]);
    fx_price($business, $a, 700);

    $svc = new PricingService();
    PricingService::resetCache();
    same(800, $svc->price($a, $normal, $agencyB)['price'], 'client normal, autre agence : promotion');
    same(950, $svc->price($a, $vipClient, $agencyB)['price'], 'VIP avant promotion');
    same(900, $svc->price($a, $normal, $agencyA)['price'], 'agence avant promotion');
    same(900, $svc->price($a, $vipClient, $agencyA)['price'], 'agence avant VIP');
    same(700, $svc->price($a, $biz, $agencyA)['price'], 'contrat avant tout');
    fx_price($business, $a, null); // prix retiré du contrat : la grille suivante s'applique
    PricingService::resetCache();
    same(900, $svc->price($a, $biz, $agencyA)['price'], 'prix retiré => grille suivante');
    Database::run('UPDATE price_lists SET active = 0 WHERE id IN (?, ?)', [$promo, $agency]);
    PricingService::resetCache();
    same(1000, $svc->price($a, $normal, $agencyA)['price'], 'grilles désactivées => standard');
});

test('tarifs : la promotion n\'est valable que sur sa période', function () {
    $a = fx_article('Pantalon');
    $std = (int)Database::value("SELECT id FROM price_lists WHERE kind = 'standard' LIMIT 1");
    fx_price($std, $a, 1200);
    $promo = fx_list('promo', ['valid_from' => date('Y-m-d', time() + 86400 * 3), 'valid_to' => date('Y-m-d', time() + 86400 * 9)]);
    fx_price($promo, $a, 500);
    PricingService::resetCache();
    same(1200, (new PricingService())->price($a, 0, fx_agency())['price'], 'promotion future : ignorée');
    same(500, (new PricingService())->price($a, 0, fx_agency(), date('Y-m-d', time() + 86400 * 4))['price'], 'pendant la période');
});

test('tarifs : article sans aucun tarif => erreur et trace d\'audit à la commande', function () {
    Auth::actAs(fx_user('comptoir', fx_agency()));
    $a = fx_article('Sans tarif', 0);
    $client = fx_client();
    PricingService::resetCache();
    throws(fn() => (new PricingService())->quote($client, ServiceLevel::Standard, [['article_id' => $a, 'qty' => 1]]), 'Aucun tarif');
    $e = throws(fn() => (new OrderService())->create(['client_id' => $client, 'lines' => [['article_id' => $a, 'qty' => 1]]]));
    ok($e instanceof PricingMissing);
    $log = Database::one("SELECT * FROM audit_log WHERE action = 'pricing.missing' ORDER BY id DESC LIMIT 1");
    ok($log !== null && (int)$log['entity_id'] === $a, 'escalade non tracée');
});

test('tarifs : chaque modification est une version motivée et auditée', function () {
    Auth::actAs(fx_user('admin', fx_agency()));
    $a = fx_article('Veste');
    $listId = fx_list('promo', ['valid_from' => date('Y-m-d'), 'valid_to' => date('Y-m-d')]);
    $list = Database::one('SELECT * FROM price_lists WHERE id = ?', [$listId]);
    $article = Database::one('SELECT id, name FROM articles WHERE id = ?', [$a]);
    $svc = new PricingService();
    throws(fn() => $svc->setPrice($list, $article, 1500, ''), 'Motif');
    throws(fn() => $svc->setPrice($list, $article, -5, 'test'), 'invalide');
    ok($svc->setPrice($list, $article, 1500, 'Lancement'));
    ok(!$svc->setPrice($list, $article, 1500, 'Idem'), 'inchangé');
    ok($svc->setPrice($list, $article, 1800, 'Hausse'));
    same(2, (int)Database::value('SELECT COUNT(*) FROM price_items WHERE list_id = ? AND article_id = ?', [$listId, $a]), 'deux versions');
    $log = Database::one("SELECT * FROM audit_log WHERE action = 'pricing.set' ORDER BY id DESC LIMIT 1");
    same('1500', $log['old_value']);
    same('1800', $log['new_value']);
    same('Hausse', $log['reason']);
});

test('ticket : TVA incluse dans le TTC, selon le taux paramétré', function () {
    SettingsService::set('tax.vat_rate', '19.25', 'test');
    same(1925, PricingService::vatIncluded(11925));
    SettingsService::set('tax.vat_rate', '0', 'test');
    same(0, PricingService::vatIncluded(11925));
});

// --- Étiquettes ----------------------------------------------------------------------------------

test('étiquettes : première impression libre, réimpression avec motif, mode manuel tracé', function () {
    Auth::actAs(fx_user('comptoir', fx_agency()));
    $order = fx_order(fx_agency(), fx_client());
    same('print', LabelService::record($order, false, ''));
    throws(fn() => LabelService::record($order, false, ''), 'Motif obligatoire');
    same('reprint', LabelService::record($order, false, 'Étiquette abîmée'));
    same('manual', LabelService::record($order, true, ''));
    same(3, (int)Database::value('SELECT COUNT(*) FROM label_prints WHERE order_id = ?', [$order]));
    $log = Database::one("SELECT * FROM audit_log WHERE action = 'labels.reprint' ORDER BY id DESC LIMIT 1");
    same('Étiquette abîmée', $log['reason']);
});

test('étiquettes : le QR est servi localement (aucun CDN)', function () {
    ok(is_file(BASE_PATH . '/public/assets/vendor/qrcode.min.js'), 'bibliothèque QR absente');
    foreach (['orders/labels', 'orders/ticket'] as $v) {
        $src = (string)file_get_contents(BASE_PATH . "/app/Views/$v.php");
        ok(!preg_match('#https?://[^"\']*qrcode#i', $src), "$v charge encore le QR depuis Internet");
    }
});

// --- Numéros uniques -------------------------------------------------------------------------------

test('numérotation : codes de commande et de pièces uniques et suivis', function () {
    $numbers = [];
    for ($i = 0; $i < 20; $i++) {
        $numbers[] = \App\Services\Numbering::next('test_seq_' . getmypid(), 'T-%d-%06d');
    }
    same(20, count(array_unique($numbers)), 'doublon de numéro');
    same('T-' . date('Y') . '-000001', $numbers[0]);
});

// --- Catalogue : l'administrateur ajoute et gère les pièces ------------------------------------------

test('catalogue : ajouter une pièce crée son prix standard, disponible tout de suite à la réception', function () {
    Auth::actAs(fx_user('direction', fx_agency()));
    $svc = new PricingService();
    $id = $svc->createArticle('Abaya brodée ' . bin2hex(random_bytes(2)), 'piece', true, 8500, 'Nouvelle pièce');
    $a = Database::one('SELECT * FROM articles WHERE id = ?', [$id]);
    same(1, (int)$a['active']);
    same(1, (int)$a['fragile']);
    PricingService::resetCache();
    same(8500, $svc->price($id, 0, fx_agency())['price'], 'prix standard applicable');
    $log = Database::one("SELECT * FROM audit_log WHERE action = 'article.create' AND entity_id = ?", [$id]);
    ok($log !== null && $log['reason'] === 'Nouvelle pièce');
    // la pièce se vend : devis immédiat, photo exigée car fragile
    $q = $svc->quote(fx_client(), ServiceLevel::Standard, [['article_id' => $id, 'qty' => 2]]);
    same(17000, $q['total']);
    same('article fragile', $q['lines'][0]['photo_reason']);
});

test('catalogue : contrôles (nom, unité, prix, motif, doublon)', function () {
    Auth::actAs(fx_user('admin', fx_agency()));
    $svc = new PricingService();
    $name = 'Pièce unique ' . bin2hex(random_bytes(2));
    throws(fn() => $svc->createArticle('X', 'piece', false, 1000, 'm'), '2 à 80');
    throws(fn() => $svc->createArticle($name, 'kg', false, 1000, 'm'), 'Unité');
    throws(fn() => $svc->createArticle($name, 'piece', false, 0, 'm'), 'Prix invalide');
    throws(fn() => $svc->createArticle($name, 'piece', false, 1000, ''), 'Motif');
    $svc->createArticle($name, 'm2', false, 2000, 'ok');
    throws(fn() => $svc->createArticle(strtoupper($name), 'piece', false, 1000, 'm'), 'existe déjà');
});

test('catalogue : modifier ou retirer une pièce, avec motif et audit; elle disparaît du poste hors-ligne', function () {
    [$ws, , $agency] = fx_station();
    $svc = new PricingService();
    $id = $svc->createArticle('Kaftan ' . bin2hex(random_bytes(2)), 'piece', false, 3000, 'Création');
    $snap = (new \App\Services\OfflineService())->snapshot($ws);
    ok(in_array($id, array_column($snap['articles'], 'id'), true), 'visible hors-ligne');
    throws(fn() => $svc->updateArticle($id, 'Kaftan', false, true, ''), 'Motif');
    ok($svc->updateArticle($id, 'Kaftan long ' . bin2hex(random_bytes(2)), true, true, 'Précision du nom'));
    ok(!$svc->updateArticle($id, (string)Database::value('SELECT name FROM articles WHERE id = ?', [$id]), true, true, 'Rien'), 'inchangé');
    ok($svc->updateArticle($id, (string)Database::value('SELECT name FROM articles WHERE id = ?', [$id]), true, false, 'Plus proposé'));
    $snap = (new \App\Services\OfflineService())->snapshot($ws);
    ok(!in_array($id, array_column($snap['articles'], 'id'), true), 'retiré du poste hors-ligne');
    $log = Database::one("SELECT * FROM audit_log WHERE action = 'article.update' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$id]);
    ok($log !== null && $log['reason'] === 'Plus proposé' && str_contains((string)$log['old_value'], '"active":1'), 'audit ancienne valeur');
    same(1, (int)Database::value('SELECT COUNT(*) FROM articles WHERE id = ?', [$id]), 'jamais supprimée');
});
