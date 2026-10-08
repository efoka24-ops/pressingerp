<?php
declare(strict_types=1);

/**
 * Installation pour SQLite : crée un schéma simplifié compatible SQLite
 * Usage: php bin/install-sqlite.php [--demo]
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement.\n");
}

$demo = in_array('--demo', $argv, true);
$pdo = Database::pdo();

// Vérifier qu'on est bien en SQLite
$dsn = (string)Config::get('db.dsn');
if (!str_starts_with($dsn, 'sqlite:')) {
    exit("Ce script est pour SQLite uniquement. DSN: $dsn\n");
}

// Effacer la base existante
$pdo->exec('PRAGMA foreign_keys = OFF');
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $pdo->exec("DROP TABLE IF EXISTS $table");
}
$pdo->exec('PRAGMA foreign_keys = ON');

echo "→ Création du schéma SQLite…\n";

$pdo->exec('CREATE TABLE agencies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  phone VARCHAR(30) NULL,
  is_workshop BOOLEAN NOT NULL DEFAULT 0
)');

$pdo->exec('CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  agency_id INTEGER NOT NULL,
  name VARCHAR(100) NOT NULL,
  login VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  pin_hash VARCHAR(255) NULL,
  role VARCHAR(20) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (agency_id) REFERENCES agencies(id)
)');

$pdo->exec('CREATE TABLE clients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code VARCHAR(20) NOT NULL UNIQUE,
  type VARCHAR(12) NOT NULL DEFAULT "particulier",
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL UNIQUE,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  is_vip BOOLEAN NOT NULL DEFAULT 0,
  credit_limit INTEGER NOT NULL DEFAULT 0,
  payment_terms_days INTEGER NOT NULL DEFAULT 0,
  preferred_channel VARCHAR(12) NOT NULL DEFAULT "sms",
  preferences TEXT NULL,
  notes TEXT NULL,
  loyalty_points INTEGER NOT NULL DEFAULT 0,
  referred_by INTEGER NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (referred_by) REFERENCES clients(id) ON DELETE SET NULL
)');

$pdo->exec('CREATE TABLE articles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name VARCHAR(80) NOT NULL,
  price INTEGER NOT NULL,
  unit VARCHAR(5) NOT NULL DEFAULT "piece",
  fragile BOOLEAN NOT NULL DEFAULT 0,
  sort INTEGER NOT NULL DEFAULT 0,
  active BOOLEAN NOT NULL DEFAULT 1
)');

$pdo->exec('CREATE TABLE orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  number VARCHAR(20) NOT NULL UNIQUE,
  tracking_token CHAR(32) NOT NULL UNIQUE,
  client_id INTEGER NOT NULL,
  agency_id INTEGER NOT NULL,
  user_id INTEGER NULL,
  service_level VARCHAR(10) NOT NULL DEFAULT "standard",
  status VARCHAR(12) NOT NULL DEFAULT "en_atelier",
  promised_at DATETIME NOT NULL,
  subtotal INTEGER NOT NULL DEFAULT 0,
  surcharge INTEGER NOT NULL DEFAULT 0,
  discount INTEGER NOT NULL DEFAULT 0,
  discount_label VARCHAR(100) NULL,
  delivery_fee INTEGER NOT NULL DEFAULT 0,
  total INTEGER NOT NULL DEFAULT 0,
  paid INTEGER NOT NULL DEFAULT 0,
  on_account BOOLEAN NOT NULL DEFAULT 0,
  invoice_id INTEGER NULL,
  delivery_address VARCHAR(255) NULL,
  rail VARCHAR(10) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ready_at DATETIME NULL,
  picked_up_at DATETIME NULL,
  picked_up_by INTEGER NULL,
  FOREIGN KEY (client_id) REFERENCES clients(id),
  FOREIGN KEY (agency_id) REFERENCES agencies(id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
)');

$pdo->exec('CREATE TABLE garments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  seq SMALLINT NOT NULL,
  code VARCHAR(30) NOT NULL UNIQUE,
  article_id INTEGER NULL,
  label VARCHAR(80) NOT NULL,
  qty DECIMAL(6,2) NOT NULL DEFAULT 1,
  price INTEGER NOT NULL DEFAULT 0,
  brand VARCHAR(60) NULL,
  color VARCHAR(40) NULL,
  material VARCHAR(60) NULL,
  damages VARCHAR(255) NULL,
  photo_path VARCHAR(255) NULL,
  step VARCHAR(12) NOT NULL,
  status VARCHAR(12) NOT NULL,
  assigned_to INTEGER NULL,
  rework_count INTEGER NOT NULL DEFAULT 0,
  step_since DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
)');

$pdo->exec('CREATE TABLE objectives (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  month VARCHAR(7) NOT NULL,
  metric VARCHAR(20) NOT NULL,
  target INTEGER NOT NULL
)');

$pdo->exec('CREATE TABLE schema_migrations (
  name VARCHAR(100) PRIMARY KEY,
  applied_at DATETIME NOT NULL
)');

echo "→ Données de démonstration…\n";

// Agences
$agencies = [];
foreach ([
    ['AK', 'Akwa (Douala)', '+237 6 99 00 10 20', 0],
    ['BO', 'Bonamoussadi (Douala)', '+237 6 99 00 30 40', 0],
    ['BE', 'Bastos (Yaoundé)', '+237 6 99 00 50 60', 0],
    ['AT', 'Atelier central', null, 1],
] as [$code, $name, $phone, $ws]) {
    $stmt = $pdo->prepare('INSERT INTO agencies (code, name, phone, is_workshop) VALUES (?, ?, ?, ?)');
    $stmt->execute([$code, $name, $phone, $ws]);
    $agencies[$code] = $pdo->lastInsertId();
}

// Utilisateurs de démo
$password = password_hash('pressing2026', PASSWORD_DEFAULT);
$pin = password_hash('1234', PASSWORD_DEFAULT);

foreach ([
    ['direction', 'Awa Njoya', 'direction', 'AK', 0],
    ['manager.akwa', 'Paul Essomba', 'manager', 'AK', 0],
    ['fatou.diallo', 'Fatou Tchamba', 'comptoir', 'AK', 0],
    ['atelier.bamba', 'Adama Sidibé', 'atelier', 'AT', 1],
    ['qualite.aka', 'Nadège Abanda', 'qualite', 'AT', 0],
] as [$login, $name, $role, $agency, $hasPin]) {
    $stmt = $pdo->prepare('INSERT INTO users (agency_id, name, login, password_hash, pin_hash, role, active) VALUES (?, ?, ?, ?, ?, ?, 1)');
    $stmt->execute([$agencies[$agency], $name, $login, $password, $hasPin ? $pin : null, $role]);
}

// Articles
foreach ([
    ['Chemise', 1000], ['Pantalon', 1200], ['Costume 2 pièces', 4500],
    ['Veste', 2500], ['Robe', 2500], ['Robe de soirée', 6000],
] as $i => [$name, $price]) {
    $stmt = $pdo->prepare('INSERT INTO articles (name, price, unit, fragile, sort, active) VALUES (?, ?, ?, 0, ?, 1)');
    $stmt->execute([$name, $price, 'piece', $i]);
}

// Objectifs
foreach ([['ca', 14000000], ['ca_jour', 500000]] as [$metric, $target]) {
    $stmt = $pdo->prepare('INSERT INTO objectives (month, metric, target) VALUES (?, ?, ?)');
    $stmt->execute([date('Y-m'), $metric, $target]);
}

echo "✓ Installation réussie. Connexion : direction / pressing2026\n";
