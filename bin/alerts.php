<?php
declare(strict_types=1);

/**
 * Évalue les alertes (retards, pièces non prises en charge, incidents, escalades) : php bin/alerts.php
 * Cron conseillé (panneau Camoo), chaque minute : * * * * * php /home/trugro9159/pressing-erp/pressing/bin/alerts.php
 * Sans cron, les alertes sont évaluées au fil des pages (une fois par minute).
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\AlertService;

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
$out = AlertService::tick();
echo $out ? implode(', ', array_map(fn($k, $v) => "$k : $v", array_keys($out), $out)) : 'Aucune alerte ouverte', "\n";
