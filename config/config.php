<?php
declare(strict_types=1);

$config = [
    'app' => [
        'name'     => 'Pressing ERP',
        'url'      => getenv('APP_URL') ?: 'http://localhost:8000',
        'timezone' => 'Africa/Douala',
        'debug'    => (getenv('APP_DEBUG') ?: '1') === '1',
    ],
    'db' => [
        'dsn'  => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=pressing_erp;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ],
    // Fidélité
    'loyalty' => [
        'fcfa_per_point'   => 100,   // 1 point pour 100 FCFA
        'every_nth_order'  => 10,    // remise sur la Nième commande
        'nth_discount_pct' => 10,
        'referral_bonus'   => 2000,
    ],
    'delivery_fee'        => 1500,
    'risk_orange_hours'   => 3,     // commande "orange" si promise dans moins de X h
    'stale_minutes'       => 180,   // pièce "en attente anormale" sur une étape
    'uncollected_days'    => 3,     // commande prête non retirée après X jours
    // Paiement en ligne (lien Mobile Money) : laisser vide pour masquer le bouton
    'mobile_money' => [
        'checkout_url' => getenv('MOMO_CHECKOUT_URL') ?: '',
    ],
    // Passerelle Mobile Money (Sungku -> pawaPay). Valeurs à fournir dans config.local.php
    'sungku' => [
        'base_url'       => getenv('SUNGKU_BASE_URL') ?: 'https://sungku.trugroup.cm',
        'api_key'        => getenv('SUNGKU_API_KEY') ?: '',
        'webhook_secret' => getenv('SUNGKU_WEBHOOK_SECRET') ?: '',
        'currency'       => 'XAF',
    ],
    // Jeton de migration à usage unique (vide = migrations web désactivées)
    'migrate_token' => getenv('MIGRATE_TOKEN') ?: '',
];

// Surcharges propres à l'hébergement (non versionnées) : secrets, accès base
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, (array)require $local);
}

return $config;
