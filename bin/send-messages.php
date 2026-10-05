<?php
declare(strict_types=1);

/**
 * Envoi des messages en file (SMS, WhatsApp, e-mail) : php bin/send-messages.php
 * Cron conseillé (panneau Camoo), chaque minute : * * * * * php /home/trugro9159/pressing-erp/pressing/bin/send-messages.php
 * Sans cron, un petit lot part au fil des pages visitées (une fois par minute).
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\MessageService;
use App\Services\Messaging\Gateways;

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
$r = MessageService::dispatch(200);
echo "envoyés : {$r['sent']} · nouvel essai programmé : {$r['retry']} · échecs : {$r['failed']} · en attente de canal : {$r['waiting']}\n";
foreach (Gateways::status() as $ch => $ok) {
    echo "  canal $ch : " . ($ok ? 'configuré' : 'NON configuré') . "\n";
}
