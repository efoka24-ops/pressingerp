<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Services\AccountService;
use App\Services\AgencyRequestService;
use App\Services\Messaging\Gateways;

function fx_request(array $over = []): array
{
    return array_merge([
        'pressing_name' => 'Pressing Test ' . bin2hex(random_bytes(2)), 'city' => 'Garoua', 'address' => 'Cité SIC, face au marché', 'phone' => '6' . random_int(10000000, 99999999),
        'manager_name' => 'Aïcha Bello', 'email' => 'resp' . bin2hex(random_bytes(3)) . '@example.com', 'message' => '',
    ], $over);
}

function with_mail(callable $fn, bool $configured = true, bool $fails = false): array
{
    $gw = new FakeGateway('email', $configured, $fails);
    Gateways::override(['email' => $gw, 'sms' => new FakeGateway('sms'), 'whatsapp' => new FakeGateway('whatsapp')]);
    try {
        $fn();
    } finally {
        Gateways::override(null);
    }
    return $gw->sent;
}

test('demande de pressing : champs obligatoires, e-mail et téléphone valides, prénom et nom', function () {
    $svc = new AgencyRequestService();
    throws(fn() => $svc->submit(fx_request(['pressing_name' => ''])), 'Nom du pressing');
    throws(fn() => $svc->submit(fx_request(['address' => 'rue'])), 'Adresse précise');
    throws(fn() => $svc->submit(fx_request(['manager_name' => 'Bello'])), 'prénom et le nom');
    throws(fn() => $svc->submit(fx_request(['email' => 'pas-un-email'])), 'e-mail invalide');
    throws(fn() => $svc->submit(fx_request(['phone' => '123'])), 'Numéro invalide');
});

test('demande de pressing : une demande en attente par e-mail, limite par connexion, alerte au super admin', function () {
    $svc = new AgencyRequestService();
    $in = fx_request();
    $id = $svc->submit($in, '203.0.113.9');
    same('en_attente', Database::value('SELECT status FROM agency_requests WHERE id = ?', [$id]));
    ok(Database::value("SELECT id FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL", ['areq:' . $id]) !== null, 'alerte à l\'administrateur');
    throws(fn() => $svc->submit($in), 'déjà en cours');
    $svc->submit(fx_request(), '203.0.113.9');
    $svc->submit(fx_request(), '203.0.113.9');
    throws(fn() => $svc->submit(fx_request(), '203.0.113.9'), 'Trop de demandes');
    $svc->submit(fx_request(), '198.51.100.7');   // une autre connexion n'est pas bloquée
    ok(!str_contains((string)Database::value('SELECT ip_hash FROM agency_requests WHERE id = ?', [$id]), '203.0.113.9'), 'adresse IP stockée hachée');
});

test('validation : réservée au super administrateur, crée l\'agence et le responsable, envoie les identifiants par e-mail', function () {
    $svc = new AgencyRequestService();
    $in = fx_request(['manager_name' => 'Aïcha Bello', 'city' => 'Bertoua']);
    $id = $svc->submit($in);
    Auth::actAs(fx_user('manager', fx_agency()));
    throws(fn() => $svc->approve($id), 'super administrateur');
    Auth::actAs(fx_user('direction', fx_agency()));
    throws(fn() => $svc->approve($id), 'super administrateur');

    $admin = fx_user('admin', fx_agency());
    Auth::actAs($admin);
    $r = [];
    $sent = with_mail(function () use ($svc, $id, &$r) {
        $r = $svc->approve($id, 'TB' . random_int(10, 99));
    });
    same('envoye', $r['mail']);
    $agency = Database::one('SELECT * FROM agencies WHERE id = ?', [$r['agency_id']]);
    same(0, (int)$agency['is_workshop']);
    $u = Database::one('SELECT * FROM users WHERE login = ?', [$r['login']]);
    same('manager', $u['role']);
    same((int)$agency['id'], (int)$u['agency_id']);
    same(1, (int)$u['must_change_password']);
    ok(password_verify($r['password'], $u['password_hash']));
    ok(str_starts_with($r['login'], 'aicha.bello'), 'nom d\'utilisateur prenom.nom sans accent : ' . $r['login']);
    same(1, count($sent));
    [$to, $subject, $body] = $sent[0];
    same($in['email'], $to);
    ok(str_contains($body, $r['login']) && str_contains($body, $r['password']) && str_contains($body, '/login'), 'l\'e-mail porte l\'adresse, le nom d\'utilisateur et le mot de passe provisoire');
    same('validee', Database::value('SELECT status FROM agency_requests WHERE id = ?', [$id]));
    same(null, Database::value("SELECT id FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL", ['areq:' . $id]), 'alerte fermée');
    throws(fn() => $svc->approve($id), 'déjà été traitée');
    ok(!str_contains((string)json_encode(Database::all("SELECT data FROM audit_log WHERE entity = 'users' AND entity_id = ?", [$u['id']])), $r['password']), 'le mot de passe n\'est pas dans l\'audit');
});

test('validation : le responsable créé ne voit que son agence et se connecte avec nom d\'utilisateur et mot de passe', function () {
    $svc = new AgencyRequestService();
    $id = $svc->submit(fx_request());
    Auth::actAs(fx_user('admin', fx_agency()));
    $r = [];
    with_mail(function () use ($svc, $id, &$r) {
        $r = $svc->approve($id, 'TC' . random_int(10, 99));
    });
    Auth::actAs(null);
    ok(Auth::attempt($r['login'], $r['password']), 'connexion sans choix d\'agence');
    same($r['agency_id'], Auth::agencyId(), 'l\'agence est celle du compte');
    same($r['agency_id'], Auth::scopedAgencyId(), 'limité à son agence');
    ok(Auth::role()->value === 'manager' && !Auth::can('admin'));
    ok(!empty(Auth::user()['must_change_password']));
    Auth::actAs(null);
    unset($_SESSION['uid'], $_SESSION['agency_id']);
});

test('validation : e-mail en échec ou canal non configuré => agence créée, identifiants à transmettre à la main', function () {
    $svc = new AgencyRequestService();
    Auth::actAs(fx_user('admin', fx_agency()));
    $a = $svc->submit(fx_request());
    $ra = [];
    with_mail(function () use ($svc, $a, &$ra) {
        $ra = $svc->approve($a, 'TD' . random_int(10, 99));
    }, true, true);
    same('echec', $ra['mail']);
    ok($ra['mail_error'] !== null && !str_contains($ra['mail_error'], $ra['password']), 'l\'erreur ne divulgue pas le mot de passe');
    same('echec', Database::value('SELECT mail_status FROM agency_requests WHERE id = ?', [$a]));
    ok(Database::value('SELECT id FROM users WHERE login = ?', [$ra['login']]) !== null);
    $b = $svc->submit(fx_request());
    $rb = [];
    with_mail(function () use ($svc, $b, &$rb) {
        $rb = $svc->approve($b, 'TE' . random_int(10, 99));
    }, false);
    same('non_configure', $rb['mail']);
});

test('validation : code d\'agence en doublon refusé, code et nom d\'utilisateur proposés sans collision', function () {
    $svc = new AgencyRequestService();
    Auth::actAs(fx_user('admin', fx_agency()));
    $existing = (string)Database::value('SELECT code FROM agencies ORDER BY id LIMIT 1');
    $id = $svc->submit(fx_request());
    throws(fn() => $svc->approve($id, $existing), 'existe déjà');
    throws(fn() => $svc->approve($id, 'a b'), 'Code agence');
    same('en_attente', Database::value('SELECT status FROM agency_requests WHERE id = ?', [$id]), 'rien n\'a été créé');
    $c1 = AgencyRequestService::freeCode('Yaoundé');
    Database::insert('agencies', ['code' => $c1, 'name' => 'Test', 'is_workshop' => 0]);
    ok(AgencyRequestService::freeCode('Yaoundé') === $c1 . '2', 'suffixe numérique si le code est pris');
    $l1 = AgencyRequestService::freeLogin('Jean-Pierre Mballa');
    same('jean.mballa', $l1 === 'jean.mballa' || str_starts_with($l1, 'jean.mballa') ? 'jean.mballa' : $l1);
    Database::insert('users', ['agency_id' => 1, 'name' => 'x', 'login' => 'zoe.nkolo', 'password_hash' => 'x', 'role' => 'comptoir', 'active' => 1]);
    same('zoe.nkolo2', AgencyRequestService::freeLogin('Zoé Nkolo'));
});

test('refus : motif obligatoire, demandeur prévenu par e-mail avec le motif', function () {
    $svc = new AgencyRequestService();
    Auth::actAs(fx_user('admin', fx_agency()));
    $in = fx_request();
    $id = $svc->submit($in);
    throws(fn() => $svc->reject($id, 'non'), 'Motif du refus');
    $out = [];
    $sent = with_mail(function () use ($svc, $id, &$out) {
        $out = $svc->reject($id, 'Une agence existe déjà à cette adresse');
    });
    same('envoye', $out['mail']);
    same('refusee', Database::value('SELECT status FROM agency_requests WHERE id = ?', [$id]));
    ok(str_contains($sent[0][2], 'Une agence existe déjà à cette adresse'));
    throws(fn() => $svc->reject($id, 'Deuxième refus inutile'), 'déjà été traitée');
});

test('mot de passe provisoire : règles du nouveau mot de passe, et le drapeau est levé après changement', function () {
    $agency = fx_agency();
    $u = fx_user('manager', $agency);
    Database::update('users', ['must_change_password' => 1, 'password_hash' => password_hash('Provisoire12', PASSWORD_DEFAULT)], 'id = :id', ['id' => $u['id']]);
    $u = Database::one('SELECT * FROM users WHERE id = ?', [$u['id']]);
    Auth::actAs($u);
    throws(fn() => AccountService::changePassword($u, 'mauvais', 'NouveauMdp123', 'NouveauMdp123'), 'actuel incorrect');
    throws(fn() => AccountService::changePassword($u, 'Provisoire12', 'court1', 'court1'), '10 caractères');
    throws(fn() => AccountService::changePassword($u, 'Provisoire12', 'uniquementdeslettres', 'uniquementdeslettres'), 'chiffres');
    throws(fn() => AccountService::changePassword($u, 'Provisoire12', 'NouveauMdp123', 'Autre123456'), 'confirmation');
    throws(fn() => AccountService::changePassword($u, 'Provisoire12', 'Provisoire12', 'Provisoire12'), 'différent');
    AccountService::changePassword($u, 'Provisoire12', 'NouveauMdp123', 'NouveauMdp123');
    $n = Database::one('SELECT * FROM users WHERE id = ?', [$u['id']]);
    same(0, (int)$n['must_change_password']);
    ok(password_verify('NouveauMdp123', $n['password_hash']));
});

test('suivi client : le numéro du ticket ou le code d\'une pièce retrouve la commande, avec son historique', function () {
    $f = fx_delivery(6000, 6000);
    $client = Database::one('SELECT phone FROM clients WHERE id = ?', [$f['client']]);
    Database::update('orders', ['ready_at' => now()], 'id = :id', ['id' => $f['order']]);
    Database::insert('payments', ['client_id' => $f['client'], 'order_id' => $f['order'], 'method' => 'especes', 'amount' => 6000, 'user_id' => null, 'created_at' => now(), 'kind' => 'payment', 'receipt_no' => 'RC-T-' . bin2hex(random_bytes(4))]);
    $order = Database::one('SELECT * FROM orders WHERE id = ?', [$f['order']]);
    $_SERVER['REQUEST_URI'] = '/suivi/' . $order['tracking_token'];
    $html = render_page('/suivi/' . $order['tracking_token']);
    ok(str_contains($html, 'Historique de ma commande') && str_contains($html, 'Commande enregistrée') && str_contains($html, 'Commande prête') && str_contains($html, 'Paiement reçu'));
    ok(!str_contains($html, 'Test comptoir') && !str_contains($html, 'Test manager'), 'aucun nom d\'agent affiché au client');
    $lookup = render_page('/suivi');
    ok(str_contains($lookup, 'étiquette') && str_contains($lookup, 'name="number"'));
});

test('pages : connexion sans choix d\'agence, demande d\'ouverture publique, examen des demandes par le super admin', function () {
    Auth::actAs(null);
    $login = render_page('/login');
    ok(str_contains($login, 'Nom d\'utilisateur') && !str_contains($login, 'name="agency_id"') && str_contains($login, '/ouvrir-un-pressing') && str_contains($login, '/suivi'));
    $form = render_page('/ouvrir-un-pressing');
    ok(str_contains($form, 'name="pressing_name"') && str_contains($form, 'name="manager_name"') && str_contains($form, 'name="email"'));
    Auth::actAs(fx_user('admin', fx_agency()));
    (new AgencyRequestService())->submit(fx_request(['pressing_name' => 'Pressing Visible Test']));
    $html = render_page('/admin/demandes');
    ok(str_contains($html, 'Pressing Visible Test') && str_contains($html, 'Valider et envoyer les identifiants'));
    ok(str_contains(render_page('/admin'), '/admin/demandes'));
});

test('accueil public : un client qui ouvre le site tombe sur le suivi de commande, pas sur la connexion du personnel', function () {
    Auth::actAs(null);
    unset($_SESSION['uid']);
    $html = render_page('/');
    ok(str_contains($html, 'Où en est mon linge') && str_contains($html, 'name="number"') && str_contains($html, 'name="phone"') && str_contains($html, '/login'));
});
