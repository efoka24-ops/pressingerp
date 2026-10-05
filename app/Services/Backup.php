<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Sauvegarde de la base : mysqldump compressé, vérifié, avec rétention et copie externalisée optionnelle (FTP).
 * Config (config.local.php) : 'backup' => ['keep' => 14, 'ftp' => ['host' => '', 'user' => '', 'pass' => '', 'dir' => '/']]
 */
final class Backup
{
    public static function dir(): string
    {
        $dir = BASE_PATH . '/storage/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $deny = dirname($dir) . '/.htaccess';
        if (!is_file($deny)) {
            file_put_contents($deny, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
        }
        return $dir;
    }

    /** @return list<array{name:string,size:int,at:int}> du plus récent au plus ancien */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.sql.gz') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'at' => (int)filemtime($f)];
        }
        usort($out, fn($a, $b) => $b['at'] <=> $a['at']);
        return $out;
    }

    /**
     * Crée une sauvegarde, la vérifie, applique la rétention et la copie à distance si configuré.
     * @return array{file:string,size:int,tables:int,verified:bool,remote:?string,log:list<string>}
     */
    public static function run(): array
    {
        $log = [];
        if (!function_exists('exec')) {
            throw new \RuntimeException('exec() indisponible : sauvegarde impossible sur cet hébergement.');
        }
        $dsn = (string)Config::get('db.dsn');
        preg_match('/host=([^;]+)/', $dsn, $h);
        preg_match('/port=(\d+)/', $dsn, $pt);
        preg_match('/dbname=([^;]+)/', $dsn, $d);
        $host = $h[1] ?? 'localhost';
        $port = $pt[1] ?? '3306';
        $db = $d[1] ?? '';

        $stamp = date('Ymd-His');
        $sql = self::dir() . "/$stamp.sql";
        $gz = $sql . '.gz';
        putenv('MYSQL_PWD=' . (string)Config::get('db.pass'));
        $cmd = sprintf(
            'mysqldump --single-transaction --routines --triggers --no-tablespaces --default-character-set=utf8mb4 -h %s -P %s -u %s %s > %s 2> %s',
            escapeshellarg($host), escapeshellarg($port), escapeshellarg((string)Config::get('db.user')), escapeshellarg($db), escapeshellarg($sql), escapeshellarg($sql . '.err')
        );
        exec($cmd, $unused, $code);
        putenv('MYSQL_PWD');
        $err = is_file($sql . '.err') ? trim((string)file_get_contents($sql . '.err')) : '';
        @unlink($sql . '.err');
        if ($code !== 0 || !is_file($sql) || filesize($sql) < 100) {
            @unlink($sql);
            throw new \RuntimeException('mysqldump a échoué (code ' . $code . ') ' . preg_replace('/pass\S*/i', '***', $err));
        }
        $log[] = 'dump : ' . number_format((int)filesize($sql)) . ' octets';

        // Compression en flux, puis vérification de l'archive
        $in = fopen($sql, 'rb');
        $out = gzopen($gz, 'wb9');
        while (!feof($in)) {
            gzwrite($out, (string)fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
        @unlink($sql);

        $check = self::check($gz);
        $log[] = "tables dans la sauvegarde : {$check['tables']} / base : {$check['expected']}";

        // Rétention
        $keep = (int)Config::get('backup.keep', 14);
        foreach (array_slice(self::list(), max(1, $keep)) as $old) {
            @unlink(self::dir() . '/' . $old['name']);
            $log[] = 'supprimée (rétention) : ' . $old['name'];
        }

        // Ancrage de l'audit hors base, puis copie externalisée de l'archive ET de l'ancre
        $anchor = Audit::writeAnchor();
        $log[] = $anchor ? 'ancrage audit : #' . $anchor['id'] : 'ancrage audit : journal vide';
        $remote = null;
        $ftp = (array)Config::get('backup.ftp', []);
        if (!empty($ftp['host']) && $check['ok']) {
            $remote = self::upload($gz, $ftp);
            if ($anchor) {
                self::upload(Audit::anchorPath(), $ftp, basename($gz, '.sql.gz') . '.audit.anchor');
            }
            $log[] = 'copie externe : ' . $remote;
        }

        Audit::log('backup.run', 'backup', null, ['file' => basename($gz), 'verified' => $check['ok'], 'remote' => $remote]);
        return ['file' => basename($gz), 'size' => (int)filesize($gz), 'tables' => $check['tables'], 'verified' => $check['ok'], 'remote' => $remote, 'log' => $log];
    }

    /**
     * Contrôle d'une archive : fichier lisible, terminé normalement, autant de CREATE TABLE que de tables en base.
     * @return array{ok:bool,tables:int,expected:int,complete:bool}
     */
    public static function check(string $gzPath): array
    {
        $fh = gzopen($gzPath, 'rb');
        $tables = 0;
        $tail = '';
        while ($fh && !gzeof($fh)) {
            $chunk = (string)gzread($fh, 1 << 20);
            $tables += preg_match_all('/^CREATE TABLE /m', $chunk);
            $tail = substr($tail . $chunk, -200);
        }
        if ($fh) {
            gzclose($fh);
        }
        $expected = count(Database::all('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'));
        $complete = str_contains($tail, '-- Dump completed');
        return ['ok' => $complete && $tables === $expected, 'tables' => $tables, 'expected' => $expected, 'complete' => $complete];
    }

    /**
     * Test de restauration SANS toucher aux tables réelles : l'archive la plus récente est rejouée dans des tables
     * préfixées « rt_ » (hébergement sans seconde base), les effectifs sont comparés, puis les tables rt_ sont supprimées.
     * @return array{ok:bool,tables:int,diffs:list<string>,log:list<string>}
     */
    public static function restoreTest(): array
    {
        $log = [];
        // Tables témoins laissées par un essai interrompu : à supprimer AVANT la sauvegarde pour ne pas les archiver
        foreach (Database::all("SHOW TABLES LIKE 'rt\\_%'") as $row) {
            self::dropShadowTable(substr((string)array_values($row)[0], 3));
        }
        $run = self::run();
        $gz = self::dir() . '/' . $run['file'];
        $tables = array_map(fn($r) => (string)array_values($r)[0], Database::all('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'));
        $tables = array_values(array_filter($tables, fn($t) => !str_starts_with($t, 'rt_')));
        usort($tables, fn($a, $b) => strlen($b) <=> strlen($a));

        $sql = (string)gzdecode((string)file_get_contents($gz));
        // Aucune instruction ne doit pouvoir atteindre une table réelle : on retire les DROP et on préfixe tous les noms
        $sql = (string)preg_replace('/^DROP TABLE IF EXISTS .*$/m', '', $sql);
        foreach ($tables as $t) {
            $sql = str_replace('`' . $t . '`', '`rt_' . $t . '`', $sql);
        }
        // Les noms de contraintes sont uniques dans toute la base : on les préfixe aussi
        $sql = (string)preg_replace('/CONSTRAINT `(\w+)`/', 'CONSTRAINT `rt_$1`', $sql);
        $names = implode('|', array_map('preg_quote', $tables));
        if (preg_match('/(CREATE TABLE|INSERT INTO|LOCK TABLES|ALTER TABLE|REFERENCES)\s+`(' . $names . ')`/', $sql, $m)) {
            throw new \RuntimeException('Refus : une instruction vise encore une table réelle (' . $m[2] . ').');
        }
        $tmp = self::dir() . '/restore-test.sql';
        file_put_contents($tmp, $sql);

        foreach ($tables as $t) {
            self::dropShadowTable($t);
        }
        $dsn = (string)Config::get('db.dsn');
        preg_match('/host=([^;]+)/', $dsn, $h);
        preg_match('/dbname=([^;]+)/', $dsn, $d);
        putenv('MYSQL_PWD=' . (string)Config::get('db.pass'));
        $cmd = sprintf('mysql -h %s -u %s %s < %s 2> %s', escapeshellarg($h[1] ?? 'localhost'), escapeshellarg((string)Config::get('db.user')), escapeshellarg($d[1] ?? ''), escapeshellarg($tmp), escapeshellarg($tmp . '.err'));
        exec($cmd, $unused, $code);
        putenv('MYSQL_PWD');
        $err = is_file($tmp . '.err') ? trim((string)file_get_contents($tmp . '.err')) : '';
        @unlink($tmp);
        @unlink($tmp . '.err');
        $log[] = 'archive : ' . $run['file'] . ' — import mysql : code ' . $code . ($err !== '' ? ' (' . mb_substr($err, 0, 200) . ')' : '');

        $diffs = [];
        try {
            if ($code === 0) {
                foreach ($tables as $t) {
                    $live = (int)Database::value('SELECT COUNT(*) FROM `' . $t . '`');
                    $copy = (int)Database::value('SELECT COUNT(*) FROM `rt_' . $t . '`');
                    // La sauvegarde elle-même ajoute 1 ligne à l'audit après le dump : écart attendu de 1 au plus
                    $expectedGap = $t === 'audit_log' ? ($live - $copy >= 0 && $live - $copy <= 1) : $live === $copy;
                    if (!$expectedGap) {
                        $diffs[] = "$t : base $live ligne(s), restauration $copy";
                    }
                }
            }
        } finally {
            foreach ($tables as $t) {
                self::dropShadowTable($t);
            }
        }
        $log[] = count($tables) . ' tables comparées, ' . count($diffs) . ' écart(s)';
        return ['ok' => $code === 0 && $diffs === [], 'tables' => count($tables), 'diffs' => $diffs, 'log' => $log];
    }

    /** Supprime une table témoin rt_* (contraintes désactivées : elles se référencent entre elles). Ne touche jamais aux vraies tables. */
    private static function dropShadowTable(string $table): void
    {
        $pdo = Database::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->exec('DROP TABLE IF EXISTS `rt_' . $table . '`');
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private static function upload(string $file, array $ftp, ?string $remoteName = null): string
    {
        $url = 'ftp://' . $ftp['host'] . '/' . trim((string)($ftp['dir'] ?? ''), '/') . '/' . ($remoteName ?? basename($file));
        $fh = fopen($file, 'rb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($file),
            CURLOPT_USERPWD => ($ftp['user'] ?? '') . ':' . ($ftp['pass'] ?? ''), CURLOPT_FTP_CREATE_MISSING_DIRS => true,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 300, CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($err !== '') {
            throw new \RuntimeException('Copie externe échouée : ' . $err);
        }
        return $ftp['host'] . ':' . trim((string)($ftp['dir'] ?? ''), '/');
    }
}
