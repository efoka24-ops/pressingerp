<?php
declare(strict_types=1);

/**
 * Installation : php bin/install.php [--demo]
 *  - crée les tables (ATTENTION : supprime les tables existantes)
 *  - insère les données de référence (agences, articles, comptes)
 *  - --demo : 12 mois d'historique, commandes en cours, factures, caisses, stocks…
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\InvoiceService;

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}
if (PHP_VERSION_ID < 80100) {
    exit("PHP 8.1 minimum requis.\n");
}

$demo = in_array('--demo', $argv, true);
$pdo = Database::pdo();

echo "→ Création du schéma…\n";
$sql = (string)file_get_contents(BASE_PATH . '/database/schema.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $pdo->exec($stmt);
}

echo "→ Données de référence…\n";
$ag = [];
foreach ([
    ['PL', 'Plateau', '+225 27 20 30 40 50', 0],
    ['CO', 'Cocody', '+225 27 22 44 55 66', 0],
    ['MA', 'Marcory', '+225 27 21 26 70 80', 0],
    ['AT', 'Atelier central', null, 1],
] as [$code, $name, $phone, $ws]) {
    $ag[$code] = Database::insert('agencies', ['code' => $code, 'name' => $name, 'phone' => $phone, 'is_workshop' => $ws]);
}

$password = password_hash('pressing2026', PASSWORD_DEFAULT);
$pin = password_hash('1234', PASSWORD_DEFAULT);
$users = [];
foreach ([
    ['direction', 'Awa Koné', 'direction', 'PL', false],
    ['manager.plateau', 'Paul Aké', 'manager', 'PL', false],
    ['fatou.diallo', 'Fatou Diallo', 'comptoir', 'PL', false],
    ['kouame.brou', 'Kouamé Brou', 'comptoir', 'CO', false],
    ['ines.gnagne', 'Inès Gnagne', 'comptoir', 'MA', false],
    ['atelier.bamba', 'Adama Bamba', 'atelier', 'AT', true],
    ['atelier.traore', 'Seydou Traoré', 'atelier', 'AT', true],
    ['atelier.yao', 'Kouassi Yao', 'atelier', 'AT', true],
    ['qualite.aka', 'Nadège Aka', 'qualite', 'AT', true],
    ['commercial.toure', 'Moussa Touré', 'commercial', 'PL', false],
] as [$login, $name, $role, $agency, $hasPin]) {
    $users[$login] = Database::insert('users', [
        'agency_id' => $ag[$agency], 'name' => $name, 'login' => $login, 'password_hash' => $password,
        'pin_hash' => $hasPin ? $pin : null, 'role' => $role, 'active' => 1,
    ]);
}

$articles = [];
foreach ([
    ['Chemise', 1000, 'piece', 0], ['Pantalon', 1200, 'piece', 0], ['Costume 2 pièces', 4500, 'piece', 0],
    ['Veste', 2500, 'piece', 0], ['Robe', 2500, 'piece', 0], ['Robe de soirée', 6000, 'piece', 1],
    ['Boubou / grand boubou', 3500, 'piece', 0], ['Pagne', 1000, 'piece', 0], ['Drap', 1500, 'piece', 0],
    ['Couverture', 3500, 'piece', 0], ['Nappe', 1200, 'piece', 0], ['Tenue médicale', 800, 'piece', 0],
    ['Uniforme', 1500, 'piece', 0], ['Chaussures', 3000, 'piece', 1], ['Rideau', 1800, 'm2', 0], ['Tapis', 2500, 'm2', 0],
] as $i => [$name, $price, $unit, $fragile]) {
    $articles[$name] = ['id' => Database::insert('articles', ['name' => $name, 'price' => $price, 'unit' => $unit, 'fragile' => $fragile, 'sort' => $i, 'active' => 1]), 'price' => $price, 'unit' => $unit];
}

foreach ([
    ['Détachant solvant', 'L', 3, 10, 1.4, 'ChimiCI'], ['Housses plastique', 'pcs', 840, 800, 120, 'Plastiva'],
    ['Lessive professionnelle', 'kg', 180, 60, 9, 'ChimiCI'], ['Perchloréthylène', 'L', 120, 50, 8, 'Solvanet'],
    ['Cintres', 'pcs', 2400, 800, 95, 'Plastiva'], ['Étiquettes QR thermiques', 'rouleaux', 6, 3, 0.4, 'Bureau Plus'],
    ['Amidon', 'kg', 22, 10, 1.1, 'ChimiCI'],
] as [$name, $unit, $q, $min, $use, $sup]) {
    Database::insert('stock_items', ['name' => $name, 'unit' => $unit, 'quantity' => $q, 'min_qty' => $min, 'daily_usage' => $use, 'supplier' => $sup]);
}

foreach ([[0, 'ca', 14_000_000], [0, 'ca_jour', 500_000], [0, 'recouvrement', 3_000_000], [0, 'reprise_max', 3]] as [, $metric, $target]) {
    Database::insert('objectives', ['month' => date('Y-m'), 'metric' => $metric, 'target' => $target]);
}

if (!$demo) {
    echo "✓ Installation terminée. Connexion : direction / pressing2026 (changez le mot de passe).\n";
    exit(0);
}

// ---------------------------------------------------------------------------
echo "→ Données de démonstration (patience, ~1 min)…\n";
mt_srand(2026);
$pdo->beginTransaction();

$rand = fn(array $a) => $a[array_rand($a)];
$weighted = function (array $w): string {
    $r = mt_rand(1, array_sum($w));
    foreach ($w as $k => $v) {
        if (($r -= $v) <= 0) {
            return (string)$k;
        }
    }
    return (string)array_key_first($w);
};
$counters = [];
$nextNumber = function (string $name, string $fmt, int $year) use (&$counters): string {
    $counters[$name][$year] = ($counters[$name][$year] ?? 0) + 1;
    return sprintf($fmt, $year, $counters[$name][$year]);
};

// Clients
$firsts = ['Awa', 'Mariam', 'Fatou', 'Aminata', 'Koffi', 'Yao', 'Ibrahim', 'Jean-Marc', 'Adjoua', 'Moussa', 'Salimata', 'Kouadio', 'Aya', 'Serge', 'Rokia', 'Didier', 'Nadia', 'Karim', 'Estelle', 'Hervé', 'Fanta', 'Arsène', 'Bintou', 'Wilfried'];
$lasts = ['Ouattara', 'Kouassi', 'Bakayoko', 'Koné', 'Traoré', 'Diallo', 'Sanogo', 'Yao', 'Bamba', 'Assi', 'Coulibaly', 'Kouamé', "N'Guessan", 'Touré', 'Diabaté', 'Gbagbo', 'Kacou', 'Dosso'];
$areas = ['Cocody Angré', 'Cocody Riviera 3', 'Plateau', 'Marcory Zone 4', 'Deux-Plateaux', 'Treichville', 'Yopougon', 'Bingerville'];
$clients = [];
$phones = [];
$mkPhone = function () use (&$phones): string {
    do {
        $p = '+225' . $GLOBALS['rand'](['07', '05', '01']) . sprintf('%08d', mt_rand(0, 99_999_999));
    } while (isset($phones[$p]));
    $phones[$p] = true;
    return $p;
};
$GLOBALS['rand'] = $rand;
$clientCount = 0;
$newClient = function (array $data) use (&$clientCount, $mkPhone): int {
    $clientCount++;
    return Database::insert('clients', $data + [
        'code' => sprintf('CL-%06d', $clientCount), 'type' => 'particulier', 'phone' => $mkPhone(),
        'preferred_channel' => mt_rand(0, 2) ? 'whatsapp' : 'sms', 'created_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(40, 900) . ' days')),
    ]);
};
$vip = $newClient(['name' => 'Mariam Ouattara', 'is_vip' => 1, 'address' => 'Cocody Angré', 'preferences' => "Amidon léger sur les chemises.\nRendu sur cintre avec housse.\nLivraison après 18 h.", 'notes' => 'Allergique aux parfums d\'assouplissant — lavage sans parfum.']);
$clients[] = $vip;
$clients[] = $newClient(['name' => 'Jean-Marc Kouassi', 'address' => 'Plateau']);
for ($i = 0; $i < 70; $i++) {
    $clients[] = $newClient(['name' => $rand($firsts) . ' ' . $rand($lasts), 'address' => $rand($areas), 'is_vip' => mt_rand(1, 20) === 1 ? 1 : 0]);
}
// Quelques nouveaux clients récents
for ($i = 0; $i < 6; $i++) {
    $clients[] = $newClient(['name' => $rand($firsts) . ' ' . $rand($lasts), 'address' => $rand($areas), 'created_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 20) . ' days'))]);
}

$pros = [];
foreach ([
    ['Hôtel Ivoire Lagune', 'Hôtellerie', 'Grille H-2', 18, 'Quotidienne 9 h', 1_000_000, 30],
    ['Clinique Sainte-Anne', 'Santé', 'Forfait linge médical', 12, 'Lun · Mer · Ven', 600_000, 30],
    ['Restaurant Le Baobab', 'Restauration', 'Grille R-1', 10, 'Mar · Sam', 300_000, 15],
    ['Cabinet Diallo & Associés', 'Services', 'Chemises forfait', 8, 'Hebdomadaire', 500_000, 30],
] as $k => [$name, $sector, $tariff, $disc, $sched, $limit, $terms]) {
    $id = $newClient(['name' => $name, 'type' => 'pro', 'credit_limit' => $limit, 'payment_terms_days' => $terms, 'preferred_channel' => 'email', 'email' => 'compta@' . strtolower(preg_replace('/[^a-z]/i', '', explode(' ', $name)[1] ?? 'client')) . '.ci', 'created_at' => date('Y-m-d H:i:s', strtotime('-500 days'))]);
    Database::insert('contracts', ['client_id' => $id, 'sector' => $sector, 'tariff_label' => $tariff, 'discount_pct' => $disc, 'pickup_schedule' => $sched,
        'start_date' => date('Y-m-d', strtotime('-400 days')), 'end_date' => date('Y-m-d', strtotime($k === 0 ? '+50 days' : ($k === 2 ? '+40 days' : '+200 days'))), 'active' => 1]);
    $pros[$id] = ['discount' => $disc, 'big' => $k === 0 ? [30, 60] : [8, 25]];
}

// Générateur de commandes
$pieceArticles = array_filter($articles, fn($a) => $a['unit'] === 'piece');
$agencies = [$ag['PL'] => $users['fatou.diallo'], $ag['CO'] => $users['kouame.brou'], $ag['MA'] => $users['ines.gnagne']];
$operators = [$users['atelier.bamba'], $users['atelier.traore'], $users['atelier.yao']];
$methods = ['especes' => 45, 'orange' => 20, 'wave' => 15, 'mtn' => 10, 'moov' => 4, 'carte' => 6];
$surch = ['standard' => 0, 'express' => 50, 'vip' => 20];
$delay = ['standard' => 72, 'express' => 24, 'vip' => 48];
$steps = ['tri', 'detachage', 'lavage', 'sechage', 'repassage', 'finition', 'controle', 'emballage', 'pret'];

$makeOrder = function (int $clientId, int $ts, string $state, ?int $proDiscount = null, array $opts = []) use (&$nextNumber, $articles, $pieceArticles, $agencies, $operators, $methods, $surch, $delay, $steps, $rand, $weighted, $users): int {
    $agencyId = $opts['agency'] ?? array_rand($agencies);
    $level = $opts['level'] ?? ($proDiscount !== null ? 'standard' : $weighted(['standard' => 75, 'express' => 15, 'vip' => 10]));
    $number = $nextNumber('order', 'PR-%d-%06d', (int)date('Y', $ts));
    $lines = [];
    $nItems = $proDiscount !== null ? mt_rand(...($opts['big'] ?? [8, 25])) : mt_rand(1, 6);
    for ($i = 0; $i < $nItems; $i++) {
        if ($proDiscount !== null) {
            $name = $rand(['Drap', 'Nappe', 'Tenue médicale', 'Uniforme', 'Chemise']);
        } else {
            $name = mt_rand(1, 30) === 1 ? $rand(['Rideau', 'Tapis']) : $rand(array_keys($pieceArticles));
        }
        $a = $articles[$name];
        $qty = $a['unit'] === 'm2' ? round(mt_rand(15, 60) / 10, 1) : 1;
        $lines[] = ['name' => $name, 'id' => $a['id'], 'qty' => $qty, 'price' => (int)round($a['price'] * $qty)];
    }
    $subtotal = array_sum(array_column($lines, 'price'));
    $surcharge = (int)round($subtotal * $surch[$level] / 100);
    $discount = $proDiscount !== null ? (int)round(($subtotal + $surcharge) * $proDiscount / 100) : 0;
    $total = $subtotal + $surcharge - $discount;
    $promised = $ts + $delay[$level] * 3600;
    $done = in_array($state, ['retire', 'livre'], true);
    $paid = $proDiscount !== null ? 0 : ($done ? $total : ($opts['deposit'] ?? 0));
    $readyTs = $done ? $promised - mt_rand(-6, 20) * 3600 : null;
    $orderId = Database::insert('orders', [
        'number' => $number, 'tracking_token' => bin2hex(random_bytes(16)), 'client_id' => $clientId, 'agency_id' => $agencyId,
        'user_id' => $agencies[$agencyId], 'service_level' => $level, 'status' => $state, 'promised_at' => date('Y-m-d H:i:s', $promised),
        'subtotal' => $subtotal, 'surcharge' => $surcharge, 'discount' => $discount, 'discount_label' => $discount ? "Contrat −{$proDiscount} %" : null,
        'total' => $total, 'paid' => $paid, 'on_account' => $proDiscount !== null ? 1 : 0, 'rail' => $state === 'pret' ? 'R-' . sprintf('%02d', mt_rand(1, 12)) : null,
        'created_at' => date('Y-m-d H:i:s', $ts), 'ready_at' => $readyTs ? date('Y-m-d H:i:s', min($readyTs, time() - 3600)) : ($state === 'pret' ? date('Y-m-d H:i:s', $opts['ready'] ?? time() - 7200) : null),
        'picked_up_at' => $done ? date('Y-m-d H:i:s', min(time() - 600, ($readyTs ?? $promised) + mt_rand(1, 72) * 3600)) : null,
        'picked_up_by' => $done ? $agencies[$agencyId] : null,
    ]);
    foreach ($lines as $i => $l) {
        $step = $done ? 'retire' : ($state === 'pret' ? 'pret' : ($opts['steps'][$i] ?? $steps[mt_rand(0, 7)]));
        $status = in_array($step, ['retire', 'pret'], true) ? 'termine' : ($opts['status'][$i] ?? $weighted(['a_traiter' => 60, 'en_cours' => 35, 'bloque' => 2, 'a_reprendre' => 3]));
        $stepSince = $done ? $ts : max($ts + 600, time() - mt_rand(5, $opts['stale'] ?? 200) * 60);
        $gid = Database::insert('garments', [
            'order_id' => $orderId, 'seq' => $i + 1, 'code' => sprintf('%s-%02d', $number, $i + 1), 'article_id' => $l['id'], 'label' => $l['name'],
            'qty' => $l['qty'], 'price' => $l['price'], 'color' => $rand([null, 'Blanc', 'Bleu nuit', 'Noir', 'Gris', 'Bordeaux', 'Ivoire', 'Pagne wax']),
            'step' => $step, 'status' => $status, 'assigned_to' => $status === 'en_cours' ? $rand($operators) : null,
            'step_since' => date('Y-m-d H:i:s', $stepSince), 'updated_at' => date('Y-m-d H:i:s', $stepSince),
        ]);
        if (!$done) {
            Database::insert('garment_events', ['garment_id' => $gid, 'step' => 'reception', 'action' => 'reception', 'user_id' => $agencies[$agencyId], 'created_at' => date('Y-m-d H:i:s', $ts)]);
            $idx = array_search($step, $steps, true);
            $t = $ts + 900;
            for ($k = 0; $k <= $idx; $k++) {
                $t = min($t + mt_rand(15, 70) * 60, $stepSince);
                if ($k > 0) {
                    Database::insert('garment_events', ['garment_id' => $gid, 'step' => $steps[$k - 1], 'action' => 'termine', 'user_id' => $rand($operators), 'machine' => $steps[$k - 1] === 'lavage' ? 'M' . mt_rand(1, 3) : null, 'created_at' => date('Y-m-d H:i:s', $t)]);
                }
                Database::insert('garment_events', ['garment_id' => $gid, 'step' => $steps[$k], 'action' => 'entree', 'created_at' => date('Y-m-d H:i:s', $k === $idx ? $stepSince : $t)]);
            }
            if ($status === 'bloque') {
                Database::insert('garment_events', ['garment_id' => $gid, 'step' => $step, 'action' => 'incident', 'note' => $rand(['Séchoir S2 en panne', 'Tache non identifiée, avis client requis', 'Accroc constaté']), 'user_id' => $rand($operators), 'created_at' => now()]);
            }
        }
        // Contrôle qualité historique (≈ 4 % de reprises)
        if ($done && mt_rand(1, 3) === 1) {
            $ko = mt_rand(1, 25) === 1;
            Database::insert('quality_checks', ['garment_id' => $gid, 'user_id' => $users['qualite.aka'], 'result' => $ko ? 'reprise' : 'conforme', 'criteria' => '{}',
                'reason' => $ko ? $rand(['Tache persistante', 'Tache persistante', 'Repassage imparfait', 'Odeur', 'Pliage']) : null,
                'back_to' => $ko ? $rand(['detachage', 'repassage', 'lavage', 'finition']) : null, 'created_at' => date('Y-m-d H:i:s', $ts + 30 * 3600)]);
        }
    }
    if ($paid > 0) {
        Database::insert('payments', ['client_id' => $clientId, 'order_id' => $orderId, 'cash_session_id' => $opts['session'] ?? null, 'method' => $weighted($methods),
            'amount' => $paid, 'user_id' => $agencies[$agencyId], 'created_at' => date('Y-m-d H:i:s', $done ? ($ts + mt_rand(30, 90) * 3600) : $ts + 120)]);
    }
    return $orderId;
};

// Historique 12 mois (commandes retirées)
$hourWeights = [8 => 14, 9 => 12, 10 => 9, 11 => 8, 12 => 5, 13 => 5, 14 => 6, 15 => 7, 16 => 9, 17 => 12, 18 => 14];
for ($d = 365; $d >= 2; $d--) {
    $day = strtotime("-$d days 00:00");
    $dow = (int)date('N', $day);
    if ($dow === 7) {
        continue;
    }
    $season = 1 + 0.25 * sin(($d / 365) * 2 * M_PI) + (in_array((int)date('n', $day), [9, 12], true) ? 0.3 : 0);
    $n = (int)round(mt_rand(10, 16) * $season * ($dow === 6 ? 1.5 : 1));
    for ($i = 0; $i < $n; $i++) {
        $ts = $day + (int)$weighted($hourWeights) * 3600 + mt_rand(0, 3599);
        $makeOrder($rand($clients), $ts, mt_rand(1, 10) === 1 ? 'livre' : 'retire');
    }
    foreach ($pros as $pid => $p) {
        if (mt_rand(1, $pid === array_key_first($pros) ? 1 : 3) === 1) {
            $makeOrder($pid, $day + 9 * 3600 + mt_rand(0, 1800), 'retire', $p['discount'], ['big' => $p['big'], 'agency' => $ag['PL']]);
        }
    }
}
echo "  · historique créé\n";

// Session de caisse ouverte aujourd'hui (Plateau) + clôture avec écart (Cocody)
$sessionPl = Database::insert('cash_sessions', ['agency_id' => $ag['PL'], 'user_id' => $users['fatou.diallo'], 'label' => 'Caisse 2', 'opened_at' => date('Y-m-d 07:58:00'), 'opening_float' => 20000, 'status' => 'ouverte']);
$sessionCo = Database::insert('cash_sessions', ['agency_id' => $ag['CO'], 'user_id' => $users['kouame.brou'], 'label' => 'Caisse 1', 'opened_at' => date('Y-m-d 08:02:00'), 'opening_float' => 15000, 'closed_at' => date('Y-m-d H:i:s', time() - 1800), 'status' => 'cloturee', 'justification' => 'Rendu monnaie erroné probable, à vérifier avec la vidéo.']);
foreach ([['especes', 78000, 63500], ['orange', 54000, 54000], ['mtn', 21500, 21500], ['wave', 24500, 24500], ['moov', 0, 0], ['carte', 8000, 8000]] as [$m, $e, $c]) {
    Database::insert('cash_counts', ['cash_session_id' => $sessionCo, 'method' => $m, 'expected' => $e, 'counted' => $c]);
}

// Commandes en cours (dernières 60 h) — dont un goulot au repassage
for ($i = 0; $i < 70; $i++) {
    $ts = time() - mt_rand(1, 60) * 3600;
    if ((int)date('G', $ts) < 8 || (int)date('G', $ts) > 19) {
        $ts -= 10 * 3600;
    }
    $isPl = mt_rand(0, 1) === 1;
    $stepsForLines = array_fill(0, 30, null);
    foreach ($stepsForLines as $k => $_) {
        $stepsForLines[$k] = $weighted(['tri' => 6, 'detachage' => 5, 'lavage' => 14, 'sechage' => 10, 'repassage' => 30, 'finition' => 9, 'controle' => 9, 'emballage' => 6]);
    }
    $makeOrder($rand($clients), $ts, 'en_atelier', null, [
        'agency' => $isPl ? $ag['PL'] : array_rand($agencies), 'steps' => $stepsForLines, 'stale' => 320,
        'deposit' => mt_rand(0, 2) === 0 ? 2000 : 0, 'session' => $isPl && $ts > strtotime('today 08:00') ? $sessionPl : null,
    ]);
}
// Commandes prêtes à retirer, dont certaines anciennes
for ($i = 0; $i < 40; $i++) {
    $ts = time() - mt_rand(30, 24 * 20) * 3600;
    $makeOrder($rand($clients), $ts, 'pret', null, ['agency' => $i % 2 ? $ag['PL'] : array_rand($agencies), 'ready' => min(time() - 3600, $ts + 30 * 3600)]);
}
// Pros en cours, dont un gros lot bloqué
$hotel = array_key_first($pros);
$makeOrder($hotel, time() - 8 * 3600, 'en_atelier', $pros[$hotel]['discount'], ['big' => [48, 48], 'agency' => $ag['PL'], 'level' => 'standard', 'steps' => array_fill(0, 48, 'repassage'), 'status' => array_fill(0, 48, 'a_traiter'), 'stale' => 320]);
Database::run("UPDATE orders SET promised_at = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE client_id = ? ORDER BY id DESC LIMIT 1", [$hotel]);
// Commande VIP en retard au détachage
$vipOrder = $makeOrder($vip, time() - 50 * 3600, 'en_atelier', null, ['agency' => $ag['CO'], 'level' => 'vip', 'steps' => ['detachage', 'controle', 'emballage'], 'status' => ['a_reprendre', 'a_traiter', 'a_traiter']]);
Database::run('UPDATE orders SET promised_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = ?', [$vipOrder]);

// Paiements du jour liés à la caisse ouverte
Database::run("UPDATE payments SET cash_session_id = ? WHERE created_at >= CURDATE() AND cash_session_id IS NULL AND order_id IN (SELECT id FROM orders WHERE agency_id = ?)", [$sessionPl, $ag['PL']]);
echo "  · commandes en cours créées\n";

// Compteurs
foreach ($counters as $name => $years) {
    foreach ($years as $year => $value) {
        Database::run('INSERT INTO counters (name, year, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $year, $value]);
    }
}
Database::run('INSERT INTO counters (name, year, value) VALUES (?, ?, ?)', ['client', (int)date('Y'), $clientCount]);
$pdo->commit();

// Factures des mois passés, puis règlements
echo "  · facturation mensuelle…\n";
$inv = new InvoiceService();
for ($m = 12; $m >= 1; $m--) {
    $inv->generate(date('Y-m', strtotime("first day of -$m month")));
}
foreach (Database::all('SELECT * FROM invoices ORDER BY id') as $i) {
    $age = (time() - strtotime($i['due_date'])) / 86400;
    $isHotel = (int)$i['client_id'] === $hotel;
    $pay = match (true) {
        $isHotel && $age > -40 => ($age > 60 ? (int)round($i['total'] * 0.4) : 0),
        $age > 60 => (int)$i['total'],
        $age > 0 => mt_rand(0, 1) ? (int)$i['total'] : 0,
        default => 0,
    };
    if ($pay > 0) {
        Database::insert('payments', ['client_id' => $i['client_id'], 'invoice_id' => $i['id'], 'method' => 'virement', 'amount' => $pay, 'reference' => 'VIR-' . mt_rand(100000, 999999), 'created_at' => date('Y-m-d H:i:s', min(time(), strtotime($i['due_date']) + mt_rand(-10, 20) * 86400))]);
        Database::update('invoices', ['paid' => $pay, 'status' => $pay >= (int)$i['total'] ? 'payee' : 'partielle'], 'id = :id', ['id' => $i['id']]);
    }
}
Database::run("UPDATE messages SET status = 'envoye', sent_at = created_at");
Database::insert('reminders', ['client_id' => $hotel, 'channel' => 'whatsapp', 'note' => 'Promesse de virement fin de mois', 'user_id' => $users['commercial.toure'], 'created_at' => date('Y-m-d H:i:s', strtotime('-5 days'))]);

// Points fidélité
Database::run("UPDATE clients c SET loyalty_points = (SELECT COALESCE(FLOOR(SUM(o.total) / 100), 0) FROM orders o WHERE o.client_id = c.id AND o.status IN ('retire', 'livre') AND o.on_account = 0)");

// Réclamations
foreach ([
    [$rand($clients), 'Chemisier décoloré', 'ouverte', 0, null],
    [$hotel, '4 nappes manquantes sur la livraison du 28', 'enquete', 0, null],
    [$rand($clients), 'Retard de 2 jours', 'geste', 2000, 'Livraison offerte proposée'],
    [$rand($clients), 'Bouton cassé', 'cloturee', 0, 'Bouton remplacé gratuitement'],
] as $k => [$cid, $subject, $status, $comp, $res]) {
    Database::insert('complaints', ['number' => sprintf('RC-%04d', $k + 1), 'client_id' => $cid, 'subject' => $subject, 'status' => $status, 'assigned_to' => $users['qualite.aka'],
        'compensation' => $comp, 'resolution' => $res, 'created_at' => date('Y-m-d H:i:s', strtotime('-' . (4 - $k) . ' days')), 'closed_at' => $status === 'cloturee' ? now() : null]);
}
Database::insert('counters', ['name' => 'complaint', 'year' => (int)date('Y'), 'value' => 4]);

// Campagnes
Database::insert('campaigns', ['name' => 'Rentrée · uniformes scolaires', 'channel' => 'sms', 'segment' => 'reguliers', 'message' => 'Bonjour {prenom}, -15 % sur les uniformes jusqu\'au 30/09 chez Pressing.', 'status' => 'envoyee', 'sent_count' => 0, 'sent_at' => date('Y-m-d H:i:s', strtotime('-30 days')), 'created_at' => date('Y-m-d H:i:s', strtotime('-31 days'))]);
Database::insert('campaigns', ['name' => 'Fêtes de fin d\'année · tenues', 'channel' => 'whatsapp', 'segment' => 'tous', 'message' => 'Bonjour {prenom}, confiez-nous vos tenues de fête dès maintenant ! Vous avez {points} points fidélité.', 'scheduled_at' => date('Y-12-01 09:00:00'), 'status' => 'planifiee', 'created_at' => now()]);
Database::insert('campaigns', ['name' => 'Réactivation · −20 % 7 jours', 'channel' => 'auto', 'segment' => 'a_risque', 'message' => 'Bonjour {prenom}, vous nous manquez ! -20 % sur votre prochain dépôt cette semaine.', 'status' => 'brouillon', 'created_at' => now()]);

echo "✓ Démo installée.\n";
echo "  Comptes (mot de passe : pressing2026) : direction, manager.plateau, fatou.diallo, kouame.brou, atelier.bamba (PIN 1234), qualite.aka, commercial.toure\n";
