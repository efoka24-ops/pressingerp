<?php
declare(strict_types=1);

/**
 * Scénarios marketing automatiques et passage en VIP : php bin/segments.php
 * Cron conseillé (panneau Camoo), une fois par jour : 0 8 * * * php /home/trugro9159/pressing-erp/pressing/bin/segments.php
 * Sans cron, le passage est évalué au fil des pages, au plus toutes les six heures. Aucun message ne part vers un client sans consentement.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\ScenarioService;

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
$r = ScenarioService::run();
echo "Nouveaux VIP : {$r['vip']} · messages en file : {$r['queued']} · sans consentement : {$r['skipped']}\n";
foreach ($r['scenarios'] as $code => $n) {
    echo "  $code : $n\n";
}
