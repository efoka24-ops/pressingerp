<?php
declare(strict_types=1);

namespace App\Core;

use App\Domain\Role;
use App\Services\Audit;

final class Auth
{
    private static ?array $user = null;

    public static function attempt(string $login, string $secret, bool $pin = false): bool
    {
        $u = Database::one('SELECT * FROM users WHERE login = ? AND active = 1', [$login]);
        $hash = $u ? ($pin ? $u['pin_hash'] : $u['password_hash']) : null;
        if (!$hash || !password_verify($secret, $hash)) {
            usleep(400_000); // ralentit le bourrage d'identifiants
            Audit::log('auth.failed', 'users', $u ? (int)$u['id'] : null, ['login' => mb_substr($login, 0, 60), 'mode' => $pin ? 'pin' : 'password']);
            return false;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Database::update('users', [$pin ? 'pin_hash' : 'password_hash' => password_hash($secret, PASSWORD_DEFAULT)], 'id = :id', ['id' => $u['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['agency_id'] = (int)$u['agency_id'];
        self::$user = null;
        return true;
    }

    public static function user(): ?array
    {
        if (self::$user === null && isset($_SESSION['uid'])) {
            self::$user = Database::one(
                'SELECT u.*, a.name AS agency_name FROM users u JOIN agencies a ON a.id = u.agency_id WHERE u.id = ? AND u.active = 1',
                [$_SESSION['uid']]
            );
        }
        return self::$user ?: null;
    }

    public static function id(): int
    {
        return (int)(self::user()['id'] ?? 0);
    }

    /** Agence de travail (choisie à la connexion) */
    public static function agencyId(): int
    {
        return (int)($_SESSION['agency_id'] ?? self::user()['agency_id'] ?? 0);
    }

    public static function role(): ?Role
    {
        $r = self::user()['role'] ?? null;
        return $r ? Role::tryFrom($r) : null;
    }

    public static function isManager(): bool
    {
        return in_array(self::role(), [Role::Admin, Role::Direction, Role::Manager], true);
    }

    /** Agence imposée au rôle courant (0 = groupe consolidé : le rôle voit toutes les agences). */
    public static function scopedAgencyId(): int
    {
        return self::role()?->agencyScoped() ? self::agencyId() : 0;
    }

    /** Le rôle courant peut-il voir les données de cette agence ? (hors session utilisateur : oui, contexte système) */
    public static function canSeeAgency(int $agencyId): bool
    {
        $scope = self::scopedAgencyId();
        return $scope === 0 || $scope === $agencyId;
    }

    /** Fragment SQL « AND col = N » pour un rôle limité à son agence, vide sinon. */
    public static function scopeSql(string $column = 'agency_id'): string
    {
        $scope = self::scopedAgencyId();
        return $scope ? " AND $column = " . $scope : '';
    }

    /** Remplace l'utilisateur courant (tests uniquement). */
    public static function actAs(?array $user): void
    {
        self::$user = $user;
        $_SESSION['uid'] = $user['id'] ?? null;
        $_SESSION['agency_id'] = $user['agency_id'] ?? null;
    }

    public static function can(string $module, string $action = 'read'): bool
    {
        return self::role()?->can($module, $action) ?? false;
    }

    /** $perm : « auth », « module » (action déduite de la méthode HTTP) ou « module:action ». */
    public static function authorize(string $perm, string $method = 'GET', bool $hasParams = false): void
    {
        if (!self::user()) {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '/';
            }
            redirect('/login');
        }
        if ($perm === 'auth') {
            return;
        }
        [$module, $action] = str_contains($perm, ':') ? explode(':', $perm, 2) : [$perm, null];
        $action ??= $method === 'GET' ? 'read' : ($hasParams ? 'update' : 'create');
        if (!self::can($module, $action)) {
            Audit::log('access.denied', 'routes', null, ['module' => $module, 'action' => $action, 'uri' => mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 120)]);
            throw new HttpException(403, "Accès refusé : votre profil n'a pas le droit « $action » sur ce module.");
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }
}
