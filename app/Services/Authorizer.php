<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Autorisation d'une opération sensible (remise, annulation d'encaissement, confirmation manuelle).
 * Un responsable (responsable d'agence, direction, administrateur) autorise lui-même, ou saisit ses identifiants
 * sur le poste de l'agent qui demande. Le demandeur et l'autorisant sont tous deux tracés.
 */
final class Authorizer
{
    public const ROLES = ['manager', 'direction', 'admin'];

    /** @return array le responsable qui autorise (ligne users) */
    public static function manager(?string $login = null, ?string $secret = null, ?int $agencyId = null): array
    {
        $login = trim((string)$login);
        if ($login === '' && Auth::isManager()) {
            return (array)Auth::user();
        }
        $u = $login !== '' ? Database::one('SELECT * FROM users WHERE login = ? AND active = 1', [$login]) : null;
        $ok = $u && in_array($u['role'], self::ROLES, true) && $secret !== null && password_verify($secret, (string)$u['password_hash'])
            && ($u['role'] !== 'manager' || $agencyId === null || (int)$u['agency_id'] === $agencyId);
        if (!$ok) {
            usleep(400_000);
            Audit::log('auth.authorizer_failed', 'users', $u ? (int)$u['id'] : null, ['login' => mb_substr($login, 0, 60)]);
            throw new \DomainException('Autorisation refusée : un responsable doit saisir son identifiant et son mot de passe (ou être connecté).');
        }
        return $u;
    }

    /** Lit les champs auth_login / auth_password du formulaire. */
    public static function fromRequest(?int $agencyId = null): array
    {
        return self::manager((string)($_POST['auth_login'] ?? ''), (string)($_POST['auth_password'] ?? ''), $agencyId);
    }
}
