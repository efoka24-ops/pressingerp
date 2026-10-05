<?php
declare(strict_types=1);

/**
 * Envoie un e-mail réel via SmtpGateway vers le serveur SMTP factice de tests/smtp/smtp.test.js.
 * php tests/smtp/send.php PORT [ok|badpass|badrcpt]
 */
define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Services\Messaging\SmtpGateway;

[$port, $mode] = [(int)($argv[1] ?? 2525), $argv[2] ?? 'ok'];
Config::load(array_replace_recursive(Config::all(), ['mail' => [
    'host' => '127.0.0.1', 'port' => $port, 'secure' => '', 'user' => 'noreply@test.cm', 'pass' => $mode === 'badpass' ? 'mauvais' : 'secret',
    'from' => 'noreply@test.cm', 'from_name' => 'Pressing é', 'verify_tls' => false,
]]));
$gw = new SmtpGateway();
echo $gw->configured() ? "configuré\n" : "NON configuré\n";
try {
    if ($mode === 'verify') {
        echo SmtpGateway::verify(), "\n";
    } else {
        $to = $mode === 'badrcpt' ? 'inconnu@example.com' : 'client@example.com';
        $id = $gw->send($to, "Sujet accentué éè\r\nBcc: evil@example.com", "Ligne 1 avec accents éàù\n.ligne commençant par un point\nFin");
        echo "envoyé $id\n";
    }
} catch (Throwable $e) {
    echo 'ERREUR : ', $e->getMessage(), "\n";
}
