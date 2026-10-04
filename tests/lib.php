<?php
declare(strict_types=1);

/** Mini-framework de tests : test('nom', fn) puis run_tests(). Les tests s'exécutent dans une transaction annulée à la fin. */

use App\Core\Auth;
use App\Core\Database;

$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

/** Ignore le test en cours (condition d'environnement non remplie). */
function skip(string $why): never
{
    throw new SkipTest($why);
}

final class SkipTest extends RuntimeException {}

function ok(bool $cond, string $msg = 'assertion fausse'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg ? "$msg : " : '') . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
    }
}

/** Vérifie que $fn lève une exception dont le message contient $contains. */
function throws(callable $fn, string $contains = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) {
            throw new RuntimeException("exception inattendue : {$e->getMessage()} (attendu : $contains)");
        }
        return $e;
    }
    throw new RuntimeException('aucune exception levée' . ($contains !== '' ? " (attendu : $contains)" : ''));
}

/** @return array{passed:int,failed:int,skipped:int,lines:list<string>} */
function run_tests(): array
{
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    $lines = [];
    $passed = $failed = $skipped = 0;
    foreach ($GLOBALS['__tests'] as [$name, $fn]) {
        try {
            $fn();
            $passed++;
            $lines[] = "ok    $name";
        } catch (SkipTest $e) {
            $skipped++;
            $lines[] = "SKIP  $name : " . $e->getMessage();
        } catch (Throwable $e) {
            $failed++;
            $lines[] = "ECHEC $name : " . get_class($e) . ' : ' . $e->getMessage();
        }
        Auth::actAs(null);
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    return ['passed' => $passed, 'failed' => $failed, 'skipped' => $skipped, 'lines' => $lines];
}

// --- Fixtures (créées dans la transaction de test, jamais conservées) -------------------------

function fx_agency(string $prefix = 'T'): int
{
    return Database::insert('agencies', ['code' => $prefix . strtoupper(bin2hex(random_bytes(3))), 'name' => 'Test ' . $prefix, 'phone' => null, 'is_workshop' => 0]);
}

function fx_user(string $role, int $agencyId): array
{
    $id = Database::insert('users', ['agency_id' => $agencyId, 'name' => "Test $role", 'login' => 't.' . bin2hex(random_bytes(4)), 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'role' => $role, 'active' => 1]);
    return Database::one('SELECT u.*, a.name AS agency_name FROM users u JOIN agencies a ON a.id = u.agency_id WHERE u.id = ?', [$id]);
}

function fx_client(): int
{
    return Database::insert('clients', ['code' => 'T' . bin2hex(random_bytes(5)), 'name' => 'Client test', 'phone' => '+2376' . random_int(10000000, 99999999)]);
}

function fx_order(int $agencyId, int $clientId, int $total = 5000): int
{
    return Database::insert('orders', [
        'number' => 'T-' . bin2hex(random_bytes(6)), 'tracking_token' => bin2hex(random_bytes(16)), 'client_id' => $clientId, 'agency_id' => $agencyId,
        'promised_at' => date('Y-m-d H:i:s', time() + 86400), 'subtotal' => $total, 'total' => $total, 'paid' => 0,
    ]);
}

function fx_garment(int $orderId): int
{
    return Database::insert('garments', [
        'order_id' => $orderId, 'seq' => 1, 'code' => 'T-' . bin2hex(random_bytes(8)), 'label' => 'Chemise test', 'step' => 'tri', 'status' => 'a_traiter',
        'step_since' => now(), 'updated_at' => now(),
    ]);
}
