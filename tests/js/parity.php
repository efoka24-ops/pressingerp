<?php
declare(strict_types=1);
// Sort la référence PHP (dates promises, règles de téléphone et de nom) pour la comparer au JavaScript : php tests/js/parity.php
define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/app/bootstrap.php';

use App\Domain\ServiceLevel;
use App\Services\ClientService;

$out = ['dates' => [], 'phones' => [], 'names' => []];
$start = (int)strtotime('2026-10-01 00:00:00');
for ($i = 0; $i < 1500; $i++) {
    $ts = $start + $i * 2237 + ($i % 7) * 59;   // pas irrégulier : couvre heures, minuits, dimanches
    foreach (ServiceLevel::cases() as $l) {
        $out['dates'][] = [$ts, $l->delayHours(), $l->promisedAt($ts)];
    }
}
foreach (['670 12 34 56', '+237 6 70 12 34 56', '00237670123456', '222 33 44 55', '570123456', '12345', '+33612345678', '+33 6 12', '0707070707', ' 6 99-00-10-20 ', '', '+2376701234', '237670123456'] as $raw) {
    $n = ClientService::normalizePhone($raw);
    $out['phones'][] = [$raw, $n, ClientService::phoneError($n) === null, ClientService::formatPhone($n)];
}
foreach ([['Jean-Marc Mbarga', 'particulier'], ['Mbarga', 'particulier'], ['  Awa   Njoya ', 'particulier'], ['J M', 'particulier'], ['', 'particulier'], ['Hôtel Ibis', 'pro'], ['AB', 'pro'], ['Marie Claire Ngo Bayiha', 'particulier']] as [$name, $type]) {
    $out['names'][] = [$name, $type, ClientService::nameError($name, $type) === null];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
