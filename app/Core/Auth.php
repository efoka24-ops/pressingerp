<?php
declare(strict_types=1);

namespace App\Core;

use App\Domain\Role;

final class Auth
{
    private static ?array $user = null;

    public static function attempt(string $login, string $secret, bool $pin = false): bool
    {
        $u = Database::one('SELECT * FROM users WHERE login = ? AND active = 1', [$login]);
        $hash = $u ? ($pin ? $u['pin_hash'] : $u['password_hash']) : null;
        if (!$hash || !password_verify($secret, $hash)) {
            usleep(400_000); // ralentit le bourrage d'identifiants
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
        return in_array(self::role(), [Role::Direction, Role::Manager], true);
    }

    public static function can(string $module): bool
    {
        return self::role()?->can($module) ?? false;
    }

    public static function authorize(string $perm): void
    {
        if (!self::user()) {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '/';
            }
            redirect('/login');
        }
        if ($perm !== 'auth' && !self::can($perm)) {
            throw new HttpException(403, 'Accès refusé à ce module pour votre profil.');
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }
}
