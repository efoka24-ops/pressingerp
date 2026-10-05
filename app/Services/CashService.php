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

    /**
     * Caisse du livreur : ouverte automatiquement (fond 0) à sa première perception, puis clôturée par un responsable
     * comme toute caisse (sans tolérance d'écart). L'argent encaissé en tournée n'entre jamais dans la caisse du comptoir.
     */
    public function courierSession(int $driverId, int $agencyId): array
    {
        $s = $this->current($driverId);
        if (!$s) {
            $this->open($driverId, $agencyId, 0, 'Livraisons ' . date('d/m'));
            $s = $this->current($driverId);
        }
        return $s;
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

    /**
     * Données de l'état de caisse d'une session : encaissements et annulations par mode, dépenses, théorique, compté, écarts.
     * @return array<string,mixed>
     */
    public function statement(array $session): array
    {
        $payments = Database::all(
            'SELECT p.*, o.number, c.name client, u.name agent FROM payments p LEFT JOIN orders o ON o.id = p.order_id LEFT JOIN clients c ON c.id = p.client_id LEFT JOIN users u ON u.id = p.user_id
             WHERE p.cash_session_id = ? ORDER BY p.id',
            [$session['id']]
        );
        $counts = [];
        foreach (Database::all('SELECT method, expected, counted FROM cash_counts WHERE cash_session_id = ?', [$session['id']]) as $r) {
            $counts[$r['method']] = $r;
        }
        $expected = $session['status'] === 'ouverte' ? $this->expected($session) : array_map(fn($r) => (int)$r['expected'], $counts);
        return [
            'payments' => $payments,
            'expenses' => Database::all('SELECT * FROM expenses WHERE cash_session_id = ? ORDER BY id', [$session['id']]),
            'expected' => $expected,
            'counts'   => $counts,
            'agency'   => (string)Database::value('SELECT name FROM agencies WHERE id = ?', [$session['agency_id']]),
            'agent'    => (string)Database::value('SELECT name FROM users WHERE id = ?', [$session['user_id']]),
            'orders'   => Database::one(
                "SELECT COUNT(*) n, COALESCE(SUM(total), 0) total, COALESCE(SUM(paid), 0) paid FROM orders WHERE agency_id = ? AND status <> 'annule' AND created_at >= ? AND created_at <= ?",
                [$session['agency_id'], $session['opened_at'], $session['closed_at'] ?? now()]
            ),
        ];
    }

    /** Clôture : écart = compté − théorique, par mode. Aucune tolérance : tout écart exige un motif et alerte le responsable. */
    public function close(array $session, array $counted, string $justification): int
    {
        if ($session['status'] !== 'ouverte') {
            throw new \DomainException('Cette caisse est déjà clôturée.');
        }
        // Aucune tolérance : le moindre écart, même d'un seul franc ou compensé entre deux modes, doit être justifié
        $expected = $this->expected($session);
        $diff = 0;
        $gaps = [];
        foreach ($expected as $method => $exp) {
            $c = (int)($counted[$method] ?? 0);
            $diff += $c - $exp;
            if ($c !== $exp) {
                $gaps[$method] = $c - $exp;
            }
        }
        if ($gaps && mb_strlen(trim($justification)) < 8) {
            throw new \DomainException('Écart constaté (' . implode(', ', array_map(fn($m, $d) => PaymentMethod::from($m)->label() . ' ' . ($d > 0 ? '+' : '') . money($d), array_keys($gaps), $gaps)) . ') : justification détaillée obligatoire (8 caractères minimum).');
        }
        Database::transaction(function () use ($session, $expected, $counted, $justification, $diff): void {
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
                'variance'      => $diff,
            ], 'id = :id', ['id' => $session['id']]);
        });
        if ($gaps) {
            $detail = implode(', ', array_map(fn($m, $d) => PaymentMethod::from($m)->label() . ' ' . ($d > 0 ? '+' : '') . money($d), array_keys($gaps), $gaps));
            $who = (string)Database::value('SELECT u.name FROM users u WHERE u.id = ?', [$session['user_id']]);
            AlertService::raise('cash_variance', 'cash:' . $session['id'], "Écart de caisse ({$session['label']}, $who) : $detail. Motif : " . trim($justification), 'cash_session', (int)$session['id'], (int)$session['agency_id']);
        }
        Audit::log('cash.close', 'cash_sessions', (int)$session['id'], ['diff' => $diff], null, null, trim($justification) ?: null);
        return $diff;
    }
}
