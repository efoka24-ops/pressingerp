<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;

/**
 * Journal d'audit en ajout seul avec chaîne de hachage :
 * chaque ligne contient le hachage de la précédente, signé par une clé secrète (audit.key, absente de la base) :
 * modifier ou supprimer une ligne au milieu du journal est détecté par verify(). La sauvegarde ancre la dernière ligne
 * hors base (audit.anchor, copié avec l'archive) : supprimer ensuite des lignes déjà ancrées est détecté. Seules les
 * lignes écrites depuis la dernière sauvegarde peuvent encore être retirées sans trace.
 */
final class Audit
{
    /**
     * @param array $data   contexte libre
     * @param mixed $old    ancienne valeur (scalaire ou tableau)
     * @param mixed $new    nouvelle valeur
     * @param ?string $reason motif (obligatoire pour les opérations sensibles)
     */
    public static function log(string $action, string $entity, ?int $entityId = null, array $data = [], mixed $old = null, mixed $new = null, ?string $reason = null): void
    {
        $row = [
            'user_id'    => Auth::id() ?: null,
            'agency_id'  => Auth::agencyId() ?: null,
            'action'     => $action,
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'data'       => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            'old_value'  => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_value'  => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            'reason'     => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => now(),
        ];
        // Le verrou sur la dernière ligne sérialise les écritures : la chaîne ne se ramifie pas.
        Database::transaction(function () use ($row): void {
            $prev = (string)(Database::value('SELECT hash FROM audit_log WHERE hash IS NOT NULL ORDER BY id DESC LIMIT 1 FOR UPDATE') ?? '');
            $row['prev_hash'] = $prev;
            $row['hash'] = self::hash($prev, $row);
            Database::insert('audit_log', $row);
        });
    }

    /** Hachage d'une ligne : JSON canonique (clés triées) pour résister à la normalisation de MySQL. */
    public static function hash(string $prev, array $r): string
    {
        $canon = fn(?string $json) => $json === null ? null : json_encode(self::sortKeys(json_decode($json, true)), JSON_UNESCAPED_UNICODE);
        $payload = json_encode([
            $prev, (string)($r['user_id'] ?? ''), (string)($r['agency_id'] ?? ''), $r['action'], $r['entity'], (string)($r['entity_id'] ?? ''),
            $canon($r['data'] ?? null), $canon($r['old_value'] ?? null), $canon($r['new_value'] ?? null),
            (string)($r['reason'] ?? ''), (string)($r['ip'] ?? ''), (string)$r['created_at'],
        ], JSON_UNESCAPED_UNICODE);
        $key = (string)Config::get('audit.key', '');
        return $key !== '' ? hash_hmac('sha256', (string)$payload, $key) : hash('sha256', (string)$payload);
    }

    private static function sortKeys(mixed $v): mixed
    {
        if (is_array($v)) {
            if (!array_is_list($v)) {
                ksort($v);
            }
            return array_map([self::class, 'sortKeys'], $v);
        }
        return $v;
    }

    /**
     * Vérifie la chaîne.
     * @return array{checked:int, broken_id:?int}
     */
    public static function verify(): array
    {
        $prev = '';
        $n = 0;
        $stmt = Database::run('SELECT * FROM audit_log WHERE hash IS NOT NULL ORDER BY id');
        while ($r = $stmt->fetch()) {
            if ((string)$r['prev_hash'] !== $prev || !hash_equals((string)$r['hash'], self::hash($prev, $r))) {
                return ['checked' => $n, 'broken_id' => (int)$r['id']];
            }
            $prev = (string)$r['hash'];
            $n++;
        }
        // Ancrage : la dernière sauvegarde a noté (id, hachage) ; ces lignes doivent toujours exister, à l'identique
        $anchor = self::readAnchor();
        if ($anchor !== null) {
            $row = Database::one('SELECT hash FROM audit_log WHERE id = ?', [$anchor['id']]);
            if (!$row || !hash_equals((string)$row['hash'], (string)$anchor['hash'])) {
                return ['checked' => $n, 'broken_id' => (int)$anchor['id']];
            }
        }
        return ['checked' => $n, 'broken_id' => null];
    }

    public static function anchorPath(): string
    {
        return (string)(Config::get('audit.anchor') ?: BASE_PATH . '/storage/audit.anchor');
    }

    /** @return ?array{id:int,hash:string,at:string} */
    public static function readAnchor(): ?array
    {
        $f = self::anchorPath();
        $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($d) && isset($d['id'], $d['hash']) ? $d : null;
    }

    /** Note la dernière ligne du journal dans un fichier hors base (appelé par la sauvegarde). */
    public static function writeAnchor(): ?array
    {
        $r = Database::one('SELECT id, hash FROM audit_log WHERE hash IS NOT NULL ORDER BY id DESC LIMIT 1');
        if (!$r) {
            return null;
        }
        $a = ['id' => (int)$r['id'], 'hash' => $r['hash'], 'at' => now()];
        @mkdir(dirname(self::anchorPath()), 0750, true);
        file_put_contents(self::anchorPath(), json_encode($a));
        return $a;
    }
}
