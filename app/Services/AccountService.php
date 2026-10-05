<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AccountService
{
    /** Règles du nouveau mot de passe : 10 caractères minimum, lettres et chiffres, différent de l'actuel. */
    public static function changePassword(array $user, string $current, string $new, string $confirm): void
    {
        if (!password_verify($current, (string)$user['password_hash'])) {
            usleep(400_000);
            throw new \DomainException('Mot de passe actuel incorrect.');
        }
        if (strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            throw new \DomainException('Nouveau mot de passe : 10 caractères minimum, avec des lettres et des chiffres.');
        }
        if ($new !== $confirm) {
            throw new \DomainException('La confirmation ne correspond pas au nouveau mot de passe.');
        }
        if (hash_equals($current, $new)) {
            throw new \DomainException('Choisissez un mot de passe différent de l\'actuel.');
        }
        Database::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0], 'id = :id', ['id' => $user['id']]);
        Audit::log('auth.password_changed', 'users', (int)$user['id']);
    }
}
