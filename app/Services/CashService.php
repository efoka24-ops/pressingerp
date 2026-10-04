<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\PaymentMethod;

final class CashService
{
    public function current(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        return Database::one("SELECT * FROM cash_sessions WHERE user_id = ? AND status = 'ouverte' ORDER BY id DESC LIMIT 1", [$userId]);
    }

    public function open(int $userId, int $agencyId, int $float, string $label): int
    {
        if ($this->current($userId)) {
            throw new \DomainException('Vous avez déjà une caisse ouverte.');
        }
        $id = Database::insert('cash_sessions', [
            'agency_id'     => $agencyId,
            'user_id'       => $userId,
            'label'         => $label !== '' ? $label : 'Caisse',
            'opened_at'     => now(),
            'opening_float' => max(0, $float),
            'status'        => 'ouverte',
        ]);
        Audit::log('cash.open', 'cash_sessions', $id, ['float' => $float]);
        return $id;
    }

    /** Montants théoriques par mode : encaissements de la session (+ fond de caisse − dépenses pour les espèces). */
    public function expected(array $session): array
    {
        $out = [];
        foreach (PaymentMethod::counter() as $m) {
            $out[$m->value] = 0;
        }
        foreach (Database::all('SELECT method, SUM(amount) total FROM payments WHERE cash_session_id = ? GROUP BY method', [$session['id']]) as $r) {
            $out[$r['method']] = (int)$r['total'];
        }
        $expenses = (int)Database::value('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE cash_session_id = ?', [$session['id']]);
        $out['especes'] += (int)$session['opening_float'] - $expenses;
        return $out;
    }

    /** Clôture : écart = compté − théorique. Justification obligatoire en cas d'écart. */
    public function close(array $session, array $counted, string $justification): int
    {
        if ($session['status'] !== 'ouverte') {
            throw new \DomainException('Cette caisse est déjà clôturée.');
        }
        $expected = $this->expected($session);
        $diff = 0;
        $hasGap = false;
        foreach ($expected as $method => $exp) {
            $c = (int)($counted[$method] ?? 0);
            $diff += $c - $exp;
            $hasGap = $hasGap || $c !== $exp;
        }
        if ($hasGap && trim($justification) === '') {
            throw new \DomainException('Écart constaté (' . money($diff, true) . ') : justification obligatoire.');
        }
        Database::transaction(function () use ($session, $expected, $counted, $justification): void {
            foreach ($expected as $method => $exp) {
                Database::insert('cash_counts', [
                    'cash_session_id' => $session['id'],
                    'method'          => $method,
                    'expected'        => $exp,
                    'counted'         => (int)($counted[$method] ?? 0),
                ]);
            }
            Database::update('cash_sessions', [
                'status'        => 'cloturee',
                'closed_at'     => now(),
                'justification' => trim($justification) ?: null,
            ], 'id = :id', ['id' => $session['id']]);
        });
        Audit::log('cash.close', 'cash_sessions', (int)$session['id'], ['diff' => $diff], null, null, trim($justification) ?: null);
        return $diff;
    }
}
