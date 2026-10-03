<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function verify(): void
    {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || !hash_equals(self::token(), $token)) {
            throw new HttpException(419, 'Session expirée : rechargez la page et recommencez.');
        }
    }
}
