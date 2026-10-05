<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\Audit;
use App\Services\Migrator;
use App\Services\SettingsService;

test('audit : enregistre ancienne valeur, nouvelle valeur et motif', function () {
    Audit::log('test.price', 'articles', 1, ['k' => 'v'], ['price' => 1000], ['price' => 1200], 'Hausse fournisseur');
    $r = Database::one("SELECT * FROM audit_log WHERE action = 'test.price' ORDER BY id DESC LIMIT 1");
    same('{"price":1000}', $r['old_value']);
    same('{"price":1200}', $r['new_value']);
    same('Hausse fournisseur', $r['reason']);
    ok(strlen((string)$r['hash']) === 64, 'hachage absent');
});

test('audit : la chaîne de hachage est intègre après plusieurs écritures', function () {
    Audit::log('test.a', 'x', 1, ['b' => 2, 'a' => 1]);
    Audit::log('test.b', 'x', 2, [], 'avant', 'après', 'motif');
    $v = Audit::verify();
    same(null, $v['broken_id'], 'chaîne rompue');
    ok($v['checked'] >= 2);
});

test('audit : le hachage change si une valeur change', function () {
    $row = ['user_id' => 1, 'agency_id' => 1, 'action' => 'a', 'entity' => 'e', 'entity_id' => 1, 'data' => null, 'old_value' => '1', 'new_value' => '2', 'reason' => 'm', 'ip' => null, 'created_at' => '2026-01-01 00:00:00'];
    $h1 = Audit::hash('', $row);
    $row['new_value'] = '3';
    ok($h1 !== Audit::hash('', $row));
});

test('audit : UPDATE et DELETE sur audit_log sont refusés par la base', function () {
    Migrator::triggersInstalled() || skip('triggers indisponibles sur cet hébergement (SUPER requis) : protection applicative uniquement');
    Audit::log('test.immutable', 'x', 1);
    throws(fn() => Database::run("UPDATE audit_log SET action = 'falsifie' WHERE action = 'test.immutable'"), 'ajout seul');
    throws(fn() => Database::run("DELETE FROM audit_log WHERE action = 'test.immutable'"), 'ajout seul');
});

test('traçabilité : garment_events est en ajout seul', function () {
    Migrator::triggersInstalled() || skip('triggers indisponibles');
    $g = fx_garment(fx_order(fx_agency(), fx_client()));
    $id = Database::insert('garment_events', ['garment_id' => $g, 'step' => 'tri', 'action' => 'start', 'created_at' => now()]);
    throws(fn() => Database::run('UPDATE garment_events SET note = ? WHERE id = ?', ['x', $id]), 'ajout seul');
    throws(fn() => Database::run('DELETE FROM garment_events WHERE id = ?', [$id]), 'ajout seul');
});

test('stock : stock_movements est en ajout seul', function () {
    Migrator::triggersInstalled() || skip('triggers indisponibles');
    $item = Database::insert('stock_items', ['name' => 'Test', 'unit' => 'kg']);
    $id = Database::insert('stock_movements', ['stock_item_id' => $item, 'type' => 'entree', 'qty' => 5, 'created_at' => now()]);
    throws(fn() => Database::run('UPDATE stock_movements SET qty = 99 WHERE id = ?', [$id]), 'ajout seul');
    throws(fn() => Database::run('DELETE FROM stock_movements WHERE id = ?', [$id]), 'ajout seul');
});

test('paiements : suppression et modification du montant refusées', function () {
    Migrator::triggersInstalled() || skip('triggers indisponibles');
    $c = fx_client();
    $o = fx_order(fx_agency(), $c);
    $id = Database::insert('payments', ['client_id' => $c, 'order_id' => $o, 'method' => 'especes', 'amount' => 1000, 'created_at' => now()]);
    throws(fn() => Database::run('DELETE FROM payments WHERE id = ?', [$id]), 'suppression interdite');
    throws(fn() => Database::run('UPDATE payments SET amount = 1 WHERE id = ?', [$id]), 'modification interdite');
    throws(fn() => Database::run("UPDATE payments SET method = 'carte' WHERE id = ?", [$id]), 'modification interdite');
});

test('paramètres : versionnés, motif obligatoire, historique en ajout seul', function () {
    $before = SettingsService::get('cash.tolerance');
    throws(fn() => SettingsService::set('cash.tolerance', '2000', ''), 'Motif obligatoire');
    throws(fn() => SettingsService::set('cash.tolerance', 'abc', 'test'), 'numérique');
    throws(fn() => SettingsService::set('inconnu.cle', '1', 'test'), 'inconnu');
    SettingsService::set('cash.tolerance', '2500', 'Test de recette');
    same(2500, SettingsService::get('cash.tolerance'));
    SettingsService::set('cash.tolerance', '3000', 'Deuxième version');
    same(3000, SettingsService::get('cash.tolerance'));
    ok(count(SettingsService::history('cash.tolerance')) >= 2, 'historique');
    if (Migrator::triggersInstalled()) {
        throws(fn() => Database::run("UPDATE settings SET value = '1' WHERE key_name = 'cash.tolerance'"), 'ajout seul');
    }
    $a = Database::one("SELECT * FROM audit_log WHERE action = 'settings.change' ORDER BY id DESC LIMIT 1");
    same('2500', $a['old_value']);
    same('3000', $a['new_value']);
    ok($before !== null);
});

test('audit : sans triggers, le code applicatif ne modifie ni ne supprime jamais les journaux', function () {
    $forbidden = '/(UPDATE\s+(audit_log|garment_events|stock_movements|settings)\b|DELETE\s+FROM\s+(audit_log|garment_events|stock_movements|settings|payments)\b|UPDATE\s+payments\s+SET\s+(?!cash_session_id))/i';
    $hits = [];
    foreach (['app', 'bin'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php' && preg_match($forbidden, (string)file_get_contents($f->getPathname()), $m)) {
                $hits[] = str_replace(BASE_PATH, '', $f->getPathname()) . ' : ' . $m[0];
            }
        }
    }
    same([], $hits, 'écritures interdites dans le code');
});

test('audit : la chaîne est signée quand une clé est configurée', function () {
    $orig = \App\Core\Config::all();
    $row = ['user_id' => 1, 'agency_id' => 1, 'action' => 'a', 'entity' => 'e', 'entity_id' => 1, 'data' => null, 'old_value' => null, 'new_value' => null, 'reason' => null, 'ip' => null, 'created_at' => '2026-01-01 00:00:00'];
    \App\Core\Config::load(array_replace_recursive($orig, ['audit' => ['key' => 'k1']]));
    $h1 = Audit::hash('', $row);
    \App\Core\Config::load(array_replace_recursive($orig, ['audit' => ['key' => 'k2']]));
    $h2 = Audit::hash('', $row);
    \App\Core\Config::load($orig);
    ok($h1 !== $h2, 'le hachage doit dépendre de la clé');
});

test('audit : la suppression de lignes déjà ancrées est détectée', function () {
    $orig = \App\Core\Config::all();
    $f = sys_get_temp_dir() . '/audit-anchor-' . bin2hex(random_bytes(4));
    \App\Core\Config::load(array_replace_recursive($orig, ['audit' => ['anchor' => $f]]));
    try {
        Audit::log('test.anchor', 'x', 1);
        $a = Audit::writeAnchor();
        ok($a !== null, 'ancre écrite');
        same(null, Audit::verify()['broken_id'], 'ancre cohérente');
        file_put_contents($f, json_encode(['id' => $a['id'] + 1000000, 'hash' => 'x', 'at' => now()]));
        ok(Audit::verify()['broken_id'] !== null, 'ancre sur une ligne disparue doit être détectée');
    } finally {
        @unlink($f);
        \App\Core\Config::load($orig);
    }
});
