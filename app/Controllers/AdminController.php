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
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'pin_hash' => $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null,
        ]);
        Audit::log('user.create', 'users', $id, ['login' => $f['login']], null, ['role' => $role->value, 'agency_id' => $agency]);
        $this->ok("Utilisateur créé. Mot de passe : $password (affiché une seule fois).", '/admin/utilisateurs');
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
            $newId = Database::insert('agencies', $data);
            Audit::log('agency.create', 'agencies', $newId, [], null, $data);
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

    public function backups(): void
    {
        $this->view('admin/backups', ['title' => 'Sauvegardes', 'files' => Backup::list()]);
    }
}
