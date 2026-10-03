<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/helpers.php';

$config = require BASE_PATH . '/config/config.php';
App\Core\Config::load($config);
date_default_timezone_set($config['app']['timezone']);

if ($config['app']['debug']) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

if (PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_name('pressing_sid');
    session_start();

    set_exception_handler(static function (Throwable $e) use ($config): void {
        $status = $e instanceof App\Core\HttpException ? $e->status : 500;
        http_response_code($status);
        if ($status >= 500) {
            error_log((string)$e);
        }
        $message = $e instanceof App\Core\HttpException ? $e->getMessage() : 'Une erreur interne est survenue.';
        try {
            App\Core\View::render('errors/error', [
                'title'   => 'Erreur ' . $status,
                'status'  => $status,
                'message' => $message,
                'trace'   => $config['app']['debug'] && $status >= 500 ? (string)$e : null,
            ], 'public');
        } catch (Throwable) {
            echo '<h1>Erreur ' . $status . '</h1><p>' . htmlspecialchars($message) . '</p>';
        }
    });
}
