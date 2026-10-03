<?php
declare(strict_types=1);

// Serveur intégré PHP : laisser passer les fichiers statiques
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

$router = require BASE_PATH . '/app/routes.php';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
