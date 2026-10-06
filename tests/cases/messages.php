<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Services\AlertService;
use App\Services\ConsentService;
use App\Services\MessageService;
use App\Services\Messaging\Gateway;
use App\Services\Messaging\Gateways;
use App\Services\Notifier;

/** Fausse passerelle : réussit, échoue ou n'est pas configurée, et garde trace de ce qu'elle a reçu. */
final class FakeGateway implements Gateway
{
    public array $sent = [];
    public array $tried = [];
    public int $calls = 0;

    /** Nombre de tentatives pour ce texte (les autres messages en file du test n'y comptent pas). */
    public function triesFor(string $body): int
    {
        return count(array_keys($this->tried, $body, true));
    }

    public function __construct(private string $channel, private bool $configured = true, private bool $fails = false)
    {
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function configured(): bool
    {
        return $this->configured;
    }

    public function send(string $to, string $subject, string $body): ?string
    {
        $this->calls++;
        $this->tried[] = $body;
        if ($this->fails) {
            throw new RuntimeException('Fournisseur indisponible');
        }
        $this->sent[] = [$to, $subject, $body];
        return 'ref-' . $this->calls;
    }
}

function fx_msg_client(array $over = []): int
{
    return Database::insert('clients', array_merge(['code' => 'T' . bin2hex(random_bytes(5)), 'name' => 'Marie Ngo', 'phone' => '+2376' . random_int(10000000, 99999999), 'preferred_channel' => 'sms'], $over));
}

function msg_row(int $id): array
{
    return Database::one('SELECT * FROM messages WHERE id = ?', [$id]);
}

test('messagerie : modèle rendu avec ses variables, modèle désactivé = rien n\'est envoyé', function () {
    $client = fx_msg_client();
    $id = MessageService::queueEvent('deposit', $client, ['numero' => 'PR-2026-000010', 'pieces' => '3 pièces', 'date_promise' => '08/10 à 15:00', 'lien' => 'http://x/suivi/abc']);
    $m = msg_row($id);
    same('Pressing : commande PR-2026-000010 reçue (3 pièces). Prête le 08/10 à 15:00. Suivi : http://x/suivi/abc', $m['body']);
    same('operational', $m['purpose']);
    same('deposit', $m['event']);
    Database::update('message_templates', ['active' => 0], 'event = :e', ['e' => 'deposit']);
    same(null, MessageService::queueEvent('deposit', $client, ['numero' => 'X']), 'modèle désactivé');
    Database::update('message_templates', ['active' => 1], 'event = :e', ['e' => 'deposit']);
    same('Bonjour .', MessageService::render('Bonjour {inconnu}.', []), 'variable absente => vide');
});

test('messagerie : la chaîne de canaux dépend des coordonnées valides', function () {
    $phoneOnly = Database::one('SELECT * FROM clients WHERE id = ?', [fx_msg_client()]);
    same(['sms', 'whatsapp'], MessageService::chain($phoneOnly, 'operational'));
    $mail = Database::one('SELECT * FROM clients WHERE id = ?', [fx_msg_client(['email' => 'marie@example.com', 'preferred_channel' => 'email'])]);
    same(['email', 'sms', 'whatsapp'], MessageService::chain($mail, 'operational'));
    $badPhone = Database::one('SELECT * FROM clients WHERE id = ?', [fx_msg_client(['phone' => '+2371234', 'email' => 'a@b.cm'])]);
    same(['email'], MessageService::chain($badPhone, 'operational'), 'numéro invalide ignoré');
    $none = Database::one('SELECT * FROM clients WHERE id = ?', [fx_msg_client(['phone' => (string)random_int(1000, 99999)])]);
    same([], MessageService::chain($none, 'operational'));
});

test('consentement RG18 : jamais d\'offre sans consentement, par canal, avec historique et révocation', function () {
    $c = fx_msg_client(['email' => 'marie@example.com']);
    Auth::actAs(fx_user('comptoir', fx_agency()));
    same(null, MessageService::queueText($c, 'Offre -20 %', 'marketing', null, null), 'aucun consentement : aucune offre');
    same(0, (int)Database::value("SELECT COUNT(*) FROM messages WHERE client_id = ? AND purpose = 'marketing'", [$c]), 'rien en file');
    ok(ConsentService::set($c, 'sms', true, 'fiche client'));
    ok(!ConsentService::set($c, 'sms', true, 'fiche client'), 'inchangé : pas de nouvelle ligne');
    $id = MessageService::queueText($c, 'Offre -20 %', 'marketing');
    $m = msg_row($id);
    same('sms', $m['channel']);
    same(null, $m['chain'], 'jamais de repli vers un canal non consenti');
    ConsentService::set($c, 'sms', false, 'fiche client');
    same(null, MessageService::queueText($c, 'Offre -20 %', 'marketing'), 'consentement retiré');
    same(['sms' => false, 'whatsapp' => false, 'email' => false], ConsentService::current($c));
    same(2, count(ConsentService::history($c)), 'historique conservé');
    ConsentService::setFromForm($c, ['email' => '1'], 'fiche client');
    same(['sms' => false, 'whatsapp' => false, 'email' => true], ConsentService::current($c));
    $log = Database::one("SELECT * FROM audit_log WHERE action = 'consent.change' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$c]);
    ok($log !== null && str_contains((string)$log['new_value'], 'true'), 'audit du consentement');
});

test('consentement RG11 : un client sans consentement marketing reçoit quand même ses messages opérationnels', function () {
    $c = fx_msg_client();
    ok(MessageService::queueEvent('ready', $c, ['numero' => 'PR-2026-000011', 'retrait' => 'Vous pouvez passer la retirer.', 'solde' => '', 'lien' => 'http://x']) !== null);
    // un message de campagne passe par le même Notifier : marketing => consentement exigé
    Notifier::queue($c, 'Promo', null, 999999);
    same(0, (int)Database::value("SELECT COUNT(*) FROM messages WHERE client_id = ? AND purpose = 'marketing'", [$c]));
    same(1, (int)Database::value("SELECT COUNT(*) FROM messages WHERE client_id = ? AND purpose = 'operational'", [$c]));
});

test('envoi : succès, référence du fournisseur et journal des tentatives', function () {
    $sms = new FakeGateway('sms');
    Gateways::override(['sms' => $sms, 'whatsapp' => new FakeGateway('whatsapp'), 'email' => new FakeGateway('email')]);
    try {
        $c = fx_msg_client();
        $id = MessageService::queueText($c, 'Bonjour test');
        $r = MessageService::dispatch(5000);
        ok($r['sent'] >= 1);
        $m = msg_row($id);
        same('envoye', $m['status']);
        ok(str_starts_with((string)$m['provider_ref'], 'ref-'));
        same(1, count(array_filter($sms->sent, fn($s) => $s[2] === 'Bonjour test')));
        same('envoye', Database::value('SELECT status FROM message_attempts WHERE message_id = ?', [$id]));
    } finally {
        Gateways::override(null);
    }
});

test('envoi : deux essais par canal puis repli sur le canal suivant (SE11)', function () {
    $sms = new FakeGateway('sms', true, true);
    $wa = new FakeGateway('whatsapp');
    Gateways::override(['sms' => $sms, 'whatsapp' => $wa, 'email' => new FakeGateway('email', false)]);
    try {
        $now = time();
        $c = fx_msg_client();
        $id = MessageService::queueText($c, 'Votre commande est prête');
        MessageService::dispatch(5000, $now);
        $m = msg_row($id);
        same('en_attente', $m['status']);
        same('sms', $m['channel'], 'même canal au premier échec');
        ok($m['next_try_at'] !== null && strtotime($m['next_try_at']) >= $now + 290, 'nouvel essai dans 5 minutes');
        MessageService::dispatch(5000, $now + 240);
        same(1, $sms->triesFor('Votre commande est prête'), 'pas d\'essai avant l\'heure');
        MessageService::dispatch(5000, $now + 310);
        $m = msg_row($id);
        same('envoye', $m['status']);
        same('whatsapp', $m['channel'], 'repli sur WhatsApp après deux échecs SMS');
        same(2, $sms->triesFor('Votre commande est prête'));
        $attempts = Database::all('SELECT channel, status FROM message_attempts WHERE message_id = ? ORDER BY id', [$id]);
        same([['sms', 'echec'], ['sms', 'echec'], ['whatsapp', 'envoye']], array_map(fn($a) => [$a['channel'], $a['status']], $attempts), 'journal complet');
    } finally {
        Gateways::override(null);
    }
});

test('envoi : tous les canaux en échec => échec définitif et alerte à la réception (SE10, SE11)', function () {
    Gateways::override(['sms' => new FakeGateway('sms', true, true), 'whatsapp' => new FakeGateway('whatsapp', true, true), 'email' => new FakeGateway('email', false)]);
    try {
        $now = time();
        $agency = fx_agency();
        $order = fx_order($agency, fx_msg_client());
        $client = (int)Database::value('SELECT client_id FROM orders WHERE id = ?', [$order]);
        $id = MessageService::queueEvent('ready', $client, ['numero' => 'N', 'retrait' => '', 'solde' => '', 'lien' => ''], $order);
        foreach ([0, 310, 700, 1100, 1500] as $dt) {
            MessageService::dispatch(5000, $now + $dt);
        }
        $m = msg_row($id);
        same('echec', $m['status']);
        ok(str_contains((string)$m['error'], 'Tous les canaux'));
        $alert = Database::one("SELECT * FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL", ['msgfail:' . $id]);
        ok($alert !== null && $alert['target_role'] === 'comptoir' && (int)$alert['agency_id'] === $agency && str_contains($alert['message'], 'vérifier ses coordonnées'), 'alerte à la réception de l\'agence');
        ok(in_array($alert['id'], array_column(AlertService::visible(fx_user('comptoir', $agency)), 'id')), 'visible du comptoir');
        ok(!in_array($alert['id'], array_column(AlertService::visible(fx_user('comptoir', fx_agency())), 'id')), 'pas d\'une autre agence');
    } finally {
        Gateways::override(null);
    }
});

test('envoi : client sans coordonnée valide => échec immédiat et alerte (SE10)', function () {
    $c = fx_msg_client(['phone' => (string)random_int(1000, 99999)]);
    $id = MessageService::queueEvent('deposit', $c, ['numero' => 'N', 'pieces' => '1 pièce', 'date_promise' => 'demain', 'lien' => '']);
    MessageService::dispatch(5000);
    $m = msg_row($id);
    same('echec', $m['status']);
    same('Aucune coordonnée valide', $m['error']);
    ok(Database::value("SELECT id FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL", ['msgfail:' . $id]) !== null);
});

test('envoi : canal non configuré => jamais « envoyé » pour de faux, le message attend puis échoue après 24 h', function () {
    Gateways::override(['sms' => new FakeGateway('sms', false), 'whatsapp' => new FakeGateway('whatsapp', false), 'email' => new FakeGateway('email', false)]);
    try {
        $now = time();
        $c = fx_msg_client();
        $id = MessageService::queueText($c, 'Bonjour');
        $r = MessageService::dispatch(5000, $now);
        ok($r['waiting'] >= 1);
        $m = msg_row($id);
        same('en_attente', $m['status'], 'toujours en attente');
        ok(str_contains((string)$m['error'], 'Aucun canal'));
        AlertService::tick($now + 3600);
        ok(Database::value("SELECT id FROM alerts WHERE dedupe_key = 'msgstuck' AND closed_at IS NULL") !== null, 'l\'administrateur est alerté');
        MessageService::dispatch(5000, $now + 25 * 3600);
        same('echec', msg_row($id)['status'], 'abandon après 24 h');
    } finally {
        Gateways::override(null);
    }
});

test('retard : le client est prévenu une seule fois avec un nouveau délai', function () {
    $now = time();
    $agency = fx_agency();
    $client = fx_msg_client(['email' => 'late@example.com']);
    $order = fx_order($agency, $client);
    Database::update('orders', ['promised_at' => date('Y-m-d H:i:s', $now - 3600), 'status' => 'en_atelier'], 'id = :id', ['id' => $order]);
    AlertService::tick($now);
    AlertService::tick($now + 60);
    AlertService::tick($now + 120);
    same(1, (int)Database::value("SELECT COUNT(*) FROM messages WHERE event = 'late' AND order_id = ?", [$order]), 'un seul message de retard');
    ok(str_contains((string)Database::value("SELECT body FROM messages WHERE event = 'late' AND order_id = ?", [$order]), 'Nouveau délai estimé'), 'avec un nouveau délai');
});

test('messagerie : les mots de passe de configuration ne figurent dans aucun fichier du code', function () {
    $secrets = array_filter([(string)\App\Core\Config::get('mail.pass'), (string)\App\Core\Config::get('db.pass'), (string)\App\Core\Config::get('sungku.api_key'), (string)\App\Core\Config::get('audit.key')], fn($s) => strlen($s) >= 8);
    if (!$secrets) {
        skip('aucun secret configuré dans cet environnement');
    }
    foreach (['app', 'tests', 'bin', 'public', 'database'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (in_array($f->getExtension(), ['php', 'sql', 'js', 'md', 'json', 'html'], true)) {
                $src = (string)file_get_contents($f->getPathname());
                foreach ($secrets as $s) {
                    ok(!str_contains($src, $s), 'un secret de configuration apparaît dans ' . $f->getFilename());
                }
            }
        }
    }
});
