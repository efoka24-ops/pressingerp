<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Domain\Role;
use App\Services\Audit;
use App\Services\Backup;
use App\Services\SettingsService;

/** Administration : utilisateurs, agences, paramètres, journal d'audit, sauvegardes. */
final class AdminController extends Controller
{
    public function index(): void
    {
        $this->view('admin/index', [
            'title'   => 'Administration',
            'users'   => (int)Database::value('SELECT COUNT(*) FROM users WHERE active = 1'),
            'agencies' => (int)Database::value('SELECT COUNT(*) FROM agencies'),
            'audit'   => (int)Database::value('SELECT COUNT(*) FROM audit_log'),
            'backups' => count(Backup::list()),
        ]);
    }

    // --- Utilisateurs -------------------------------------------------------------------------

    public function users(): void
    {
        $this->view('admin/users', [
            'title'    => 'Utilisateurs',
            'users'    => Database::all('SELECT u.*, a.name agency FROM users u JOIN agencies a ON a.id = u.agency_id ORDER BY u.active DESC, u.role, u.name'),
            'agencies' => Database::all('SELECT id, name FROM agencies ORDER BY is_workshop, name'),
        ]);
    }

    public function createUser(): void
    {
        $f = $this->required(['name' => 'Nom', 'login' => 'Identifiant']);
        $role = Role::tryFrom($this->str('role')) ?? $this->fail('Profil invalide.');
        $agency = $this->int('agency_id');
        if (!Database::value('SELECT id FROM agencies WHERE id = ?', [$agency])) {
            $this->fail('Agence invalide.');
        }
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $f['login'])) {
            $this->fail('Identifiant : 3 à 40 caractères (minuscules, chiffres, . _ -).');
        }
        if (Database::value('SELECT id FROM users WHERE login = ?', [$f['login']])) {
            $this->fail('Cet identifiant existe déjà.');
        }
        $password = $this->str('password');
        if ($password === '') {
            $password = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12);
        } elseif (strlen($password) < 10) {
            $this->fail('Mot de passe : 10 caractères minimum.');
        }
        $pin = $this->str('pin');
        if ($pin !== '' && !preg_match('/^\d{4,8}$/', $pin)) {
            $this->fail('PIN : 4 à 8 chiffres.');
        }
        $id = Database::insert('users', [
            'agency_id' => $agency, 'name' => $f['name'], 'login' => $f['login'], 'role' => $role->value, 'active' => 1,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'pin_hash' => $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null, 'must_change_password' => 1,
        ]);
        Audit::log('user.create', 'users', $id, ['login' => $f['login']], null, ['role' => $role->value, 'agency_id' => $agency]);
        $this->ok("Utilisateur créé. Mot de passe provisoire : $password (affiché une seule fois ; il devra le changer à sa première connexion).", '/admin/utilisateurs');
    }

    public function updateUser(string $id): void
    {
        $u = Database::one('SELECT * FROM users WHERE id = ?', [(int)$id]) ?? $this->fail('Utilisateur introuvable.');
        $reason = $this->str('reason');
        if ($reason === '') {
            $this->fail('Motif obligatoire.');
        }
        $role = Role::tryFrom($this->str('role')) ?? $this->fail('Profil invalide.');
        $new = ['role' => $role->value, 'agency_id' => $this->int('agency_id'), 'active' => $this->int('active') ? 1 : 0];
        if (!Database::value('SELECT id FROM agencies WHERE id = ?', [$new['agency_id']])) {
            $this->fail('Agence invalide.');
        }
        $leavesAdmin = $u['role'] === 'admin' && ($new['role'] !== 'admin' || !$new['active']);
        if ($leavesAdmin && (int)Database::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id <> ?", [$u['id']]) === 0) {
            $this->fail('Impossible : il doit rester au moins un administrateur actif.');
        }
        if ((int)$u['id'] === Auth::id() && !$new['active']) {
            $this->fail('Vous ne pouvez pas désactiver votre propre compte.');
        }
        $old = ['role' => $u['role'], 'agency_id' => (int)$u['agency_id'], 'active' => (int)$u['active']];
        if ($old === $new) {
            $this->ok('Aucun changement.', '/admin/utilisateurs');
        }
        Database::update('users', $new, 'id = :id', ['id' => $u['id']]);
        Audit::log('user.update', 'users', (int)$u['id'], ['login' => $u['login']], $old, $new, $reason);
        $this->ok('Utilisateur mis à jour.', '/admin/utilisateurs');
    }

    public function resetPassword(string $id): void
    {
        $u = Database::one('SELECT id, login FROM users WHERE id = ?', [(int)$id]) ?? $this->fail('Utilisateur introuvable.');
        $reason = $this->str('reason');
        if ($reason === '') {
            $this->fail('Motif obligatoire.');
        }
        $password = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12);
        Database::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $u['id']]);
        Audit::log('user.reset_password', 'users', (int)$u['id'], ['login' => $u['login']], null, null, $reason);
        $this->ok("Nouveau mot de passe de {$u['login']} : $password (affiché une seule fois).", '/admin/utilisateurs');
    }

    // --- Agences ------------------------------------------------------------------------------

    public function agencies(): void
    {
        $this->view('admin/agencies', ['title' => 'Agences', 'agencies' => Database::all('SELECT * FROM agencies ORDER BY is_workshop, name')]);
    }

    public function saveAgency(string $id = ''): void
    {
        $f = $this->required(['code' => 'Code', 'name' => 'Nom']);
        $code = strtoupper($f['code']);
        if (!preg_match('/^[A-Z0-9]{2,10}$/', $code)) {
            $this->fail('Code : 2 à 10 lettres ou chiffres.');
        }
        $data = ['code' => $code, 'name' => $f['name'], 'phone' => $this->str('phone') ?: null, 'is_workshop' => $this->int('is_workshop') ? 1 : 0];
        if ($id === '') {
            if (Database::value('SELECT id FROM agencies WHERE code = ?', [$code])) {
                $this->fail('Ce code existe déjà.');
            }
            // Création d'un pressing et, dans la foulée, de son responsable d'agence (tout ou rien)
            $mLogin = trim($this->str('manager_login'));
            $mName = trim($this->str('manager_name'));
            if (($mLogin === '') !== ($mName === '')) {
                $this->fail('Responsable : renseignez son nom et son identifiant, ou laissez les deux vides.');
            }
            if ($mLogin !== '' && !preg_match('/^[a-z0-9._-]{3,40}$/', $mLogin)) {
                $this->fail('Identifiant du responsable : 3 à 40 caractères (minuscules, chiffres, . _ -).');
            }
            if ($mLogin !== '' && Database::value('SELECT id FROM users WHERE login = ?', [$mLogin])) {
                $this->fail('Cet identifiant de responsable existe déjà.');
            }
            $password = '';
            $newId = Database::transaction(function () use ($data, $mLogin, $mName, &$password): int {
                $newId = Database::insert('agencies', $data);
                Audit::log('agency.create', 'agencies', $newId, [], null, $data);
                if ($mLogin !== '') {
                    $password = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12);
                    $uid = Database::insert('users', [
                        'agency_id' => $newId, 'name' => $mName, 'login' => $mLogin, 'role' => Role::Manager->value, 'active' => 1,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'must_change_password' => 1,
                    ]);
                    Audit::log('user.create', 'users', $uid, ['login' => $mLogin], null, ['role' => Role::Manager->value, 'agency_id' => $newId]);
                }
                return $newId;
            });
            if ($mLogin !== '') {
                $this->ok("Agence créée avec son responsable. Identifiant : $mLogin · mot de passe : $password (affiché une seule fois).", '/admin/agences');
            }
        } else {
            $a = Database::one('SELECT * FROM agencies WHERE id = ?', [(int)$id]) ?? $this->fail('Agence introuvable.');
            $reason = $this->str('reason');
            if ($reason === '') {
                $this->fail('Motif obligatoire.');
            }
            $old = array_intersect_key($a, $data);
            Database::update('agencies', $data, 'id = :id', ['id' => $a['id']]);
            Audit::log('agency.update', 'agencies', (int)$a['id'], [], $old, $data, $reason);
        }
        $this->ok('Agence enregistrée.', '/admin/agences');
    }

    // --- Paramètres ---------------------------------------------------------------------------

    public function settings(): void
    {
        $groups = [];
        foreach (SettingsService::DEFINITIONS as $key => [$label, $type, $default, $group]) {
            $groups[$group][] = ['key' => $key, 'label' => $label, 'type' => $type, 'value' => SettingsService::get($key), 'default' => $default];
        }
        $this->view('admin/settings', ['title' => 'Paramètres', 'groups' => $groups, 'history' => $this->str('histo') !== '' ? SettingsService::history($this->str('histo')) : []]);
    }

    public function saveSetting(): void
    {
        try {
            SettingsService::set($this->str('key'), $this->str('value'), $this->str('reason'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Paramètre enregistré (nouvelle version).', '/admin/parametres');
    }

    // --- Audit et sauvegardes -----------------------------------------------------------------

    public function audit(): void
    {
        $where = [];
        $p = [];
        foreach (['action' => 'a.action LIKE :action', 'entity' => 'a.entity = :entity'] as $k => $sql) {
            if (($v = $this->str($k)) !== '') {
                $where[] = $sql;
                $p[$k] = $k === 'action' ? "%$v%" : $v;
            }
        }
        $rows = Database::all(
            'SELECT a.*, u.name user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.id DESC LIMIT 200',
            $p
        );
        $this->view('admin/audit', [
            'title'  => 'Journal d\'audit',
            'rows'   => $rows,
            'verify' => $this->str('verifier') !== '' ? Audit::verify() : null,
        ]);
    }

    // --- Parcours par traitement -------------------------------------------------------------

    public function routes(): void
    {
        $this->view('admin/routes', ['title' => 'Parcours de traitement', 'treatments' => Database::all('SELECT * FROM treatments ORDER BY sort, id'), 'steps' => \App\Services\WorkflowService::WORK_STEPS]);
    }

    public function saveRoute(): void
    {
        $allowed = \App\Services\WorkflowService::WORK_STEPS;
        $chosen = array_values(array_intersect($allowed, array_map('strval', (array)($_POST['steps'] ?? []))));  // ordre canonique imposé
        if (!$chosen) {
            $this->fail('Un parcours doit comporter au moins une étape de travail.');
        }
        $label = $this->required(['label' => 'Libellé'])['label'];
        $reason = $this->required(['reason' => 'Motif'])['reason'];
        $csv = implode(',', $chosen);
        $id = $this->int('id');
        if ($id) {
            $t = Database::one('SELECT * FROM treatments WHERE id = ?', [$id]) ?? $this->fail('Parcours introuvable.');
            $new = ['label' => mb_substr($label, 0, 80), 'steps' => $csv, 'active' => $this->int('active') ? 1 : 0];
            if ($new['active'] === 0 && (int)Database::value('SELECT COUNT(*) FROM treatments WHERE active = 1 AND id <> ?', [$id]) === 0) {
                $this->fail('Au moins un parcours doit rester actif.');
            }
            Database::update('treatments', $new, 'id = :id', ['id' => $id]);
            Audit::log('treatment.update', 'treatments', $id, ['code' => $t['code']], array_intersect_key($t, $new), $new, $reason);
        } else {
            $code = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace([' ', '-'], '_', $label)));
            if ($code === '' || Database::value('SELECT id FROM treatments WHERE code = ?', [$code])) {
                $this->fail('Ce libellé produit un code déjà utilisé ou vide.');
            }
            $id = Database::insert('treatments', ['code' => mb_substr($code, 0, 30), 'label' => mb_substr($label, 0, 80), 'steps' => $csv, 'active' => 1, 'sort' => 50]);
            Audit::log('treatment.create', 'treatments', $id, [], null, ['label' => $label, 'steps' => $csv], $reason);
        }
        $this->ok('Parcours enregistré.', '/admin/parcours');
    }

    // --- Règles d'alerte -----------------------------------------------------------------------

    public function alertRules(): void
    {
        $this->view('admin/alert_rules', ['title' => 'Règles d\'alerte', 'rules' => Database::all('SELECT * FROM alert_rules ORDER BY event')]);
    }

    public function saveAlertRule(): void
    {
        $r = Database::one('SELECT * FROM alert_rules WHERE event = ?', [$this->str('event')]) ?? $this->fail('Règle inconnue.');
        $reason = $this->required(['reason' => 'Motif'])['reason'];
        $role = Role::tryFrom($this->str('target_role')) ?? $this->fail('Destinataire invalide.');
        $esc = $this->str('escalate_role') !== '' ? (Role::tryFrom($this->str('escalate_role')) ?? $this->fail('Rôle d\'escalade invalide.')) : null;
        $after = $esc ? max(1, $this->int('escalate_after_minutes', 60)) : null;
        $new = [
            'priority' => in_array($this->str('priority'), ['normal', 'high', 'critical'], true) ? $this->str('priority') : 'normal',
            'target_role' => $role->value, 'delay_minutes' => max(0, $this->int('delay_minutes')),
            'escalate_role' => $esc?->value, 'escalate_after_minutes' => $after, 'active' => $this->int('active') ? 1 : 0,
        ];
        $old = array_intersect_key($r, $new);
        Database::update('alert_rules', $new, 'event = :e', ['e' => $r['event']]);
        \App\Services\AlertService::resetCache();
        Audit::log('alert_rule.update', 'alert_rules', null, ['event' => $r['event']], $old, $new, $reason);
        $this->ok('Règle enregistrée.', '/admin/alertes');
    }

    // --- Messagerie ------------------------------------------------------------------------------

    public function messages(): void
    {
        $this->view('admin/messages', [
            'title'     => 'Messagerie',
            'templates' => Database::all('SELECT * FROM message_templates ORDER BY event'),
            'events'    => \App\Services\MessageService::EVENTS,
            'channels'  => \App\Services\Messaging\Gateways::status(),
            'stats'     => Database::all("SELECT status, COUNT(*) n FROM messages WHERE created_at > NOW() - INTERVAL 7 DAY GROUP BY status"),
            'recent'    => Database::all('SELECT m.id, m.channel, m.event, m.status, m.error, m.created_at, c.name client FROM messages m JOIN clients c ON c.id = m.client_id ORDER BY m.id DESC LIMIT 15'),
            'mail'      => (array)\App\Core\Config::get('mail', []),
        ]);
    }

    public function saveTemplate(): void
    {
        $event = $this->str('event');
        $allowed = \App\Services\MessageService::EVENTS[$event] ?? $this->fail('Modèle inconnu.');
        $body = $this->required(['body' => 'Texte du message'])['body'];
        $reason = $this->required(['reason' => 'Motif'])['reason'];
        preg_match_all('/\{(\w+)\}/', $body, $m);
        if ($unknown = array_diff($m[1], $allowed)) {
            $this->fail('Variable(s) inconnue(s) : {' . implode('}, {', $unknown) . '}. Disponibles : {' . implode('}, {', $allowed) . '}.');
        }
        $t = Database::one('SELECT * FROM message_templates WHERE event = ?', [$event]);
        $new = ['body' => mb_substr($body, 0, 600), 'active' => $this->int('active') ? 1 : 0];
        Database::update('message_templates', $new + ['updated_by' => Auth::id() ?: null, 'updated_at' => now()], 'event = :e', ['e' => $event]);
        Audit::log('template.update', 'message_templates', null, ['event' => $event], array_intersect_key($t, $new), $new, $reason);
        $this->ok('Modèle enregistré.', '/admin/messages');
    }

    /** Vérifie la connexion SMTP et les identifiants, sans envoyer de message. */
    public function testSmtp(): void
    {
        try {
            $this->ok(\App\Services\Messaging\SmtpGateway::verify(), '/admin/messages');
        } catch (\Throwable $e) {
            $this->fail('SMTP : ' . $e->getMessage(), '/admin/messages');
        }
    }

    /** Envoie un e-mail de test à l'adresse saisie (celle de la personne qui teste). */
    public function testMail(): void
    {
        $to = $this->str('to');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->fail('Adresse e-mail invalide.');
        }
        try {
            (new \App\Services\Messaging\SmtpGateway())->send($to, 'Pressing : message de test', "Ceci est un message de test envoyé depuis l'administration du Pressing ERP.\nSi vous le lisez, l'envoi d'e-mails fonctionne.");
        } catch (\Throwable $e) {
            $this->fail('Envoi impossible : ' . $e->getMessage());
        }
        Audit::log('mail.test', 'messages', null, ['to' => $to]);
        $this->ok("Message de test envoyé à $to.", '/admin/messages');
    }

    public function backups(): void
    {
        $this->view('admin/backups', ['title' => 'Sauvegardes', 'files' => Backup::list()]);
    }
}
