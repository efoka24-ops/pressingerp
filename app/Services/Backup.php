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

        // Copie externalisée
        $remote = null;
        $ftp = (array)Config::get('backup.ftp', []);
        if (!empty($ftp['host']) && $check['ok']) {
            $remote = self::upload($gz, $ftp);
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

    private static function upload(string $file, array $ftp): string
    {
        $url = 'ftp://' . $ftp['host'] . '/' . trim((string)($ftp['dir'] ?? ''), '/') . '/' . basename($file);
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
