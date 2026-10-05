<?php
declare(strict_types=1);

/**
 * Relances des commandes prêtes non retirées (J+2, J+7, J+15 paramétrables) : php bin/reminders.php
 * Cron conseillé (panneau Camoo), toutes les heures : 0 * * * * php /home/trugro9159/pressing-erp/pressing/bin/reminders.php
 * Sans cron, la relance est évaluée au fil des pages avec les alertes. Idempotent : aucune relance n'est envoyée deux fois.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\ReminderService;

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
$r = ReminderService::run();
echo "Relances mises en file : {$r['queued']} · paliers sautés : {$r['skipped']} · commandes examinées : {$r['checked']}\n";
