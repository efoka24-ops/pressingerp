<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

final class Audit
{
    public static function log(string $action, string $entity, ?int $entityId = null, array $data = []): void
    {
        Database::insert('audit_log', [
            'user_id'    => Auth::id() ?: null,
            'action'     => $action,
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'data'       => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at' => now(),
        ]);
    }
}
