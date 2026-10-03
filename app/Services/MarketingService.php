<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class MarketingService
{
    public const SEGMENTS = [
        'tous'      => 'Tous les clients',
        'vip'       => 'VIP (≥ 300 000 / an ou marqués VIP)',
        'reguliers' => 'Réguliers',
        'nouveaux'  => 'Nouveaux (30 j)',
        'a_risque'  => 'À risque (inactifs 45–90 j)',
        'perdus'    => 'Perdus (> 90 j)',
        'pros'      => 'Clients professionnels',
    ];

    public const CHANNELS = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'auto' => 'Canal préféré du client'];

    /** @return array<string, list<int>> segment => ids clients */
    public function segments(): array
    {
        $rows = Database::all(
            "SELECT c.id, c.type, c.is_vip, c.created_at, MAX(o.created_at) last_order,
                    COALESCE(SUM(CASE WHEN o.created_at > NOW() - INTERVAL 1 YEAR THEN o.total ELSE 0 END), 0) year_spend
             FROM clients c LEFT JOIN orders o ON o.client_id = c.id AND o.status <> 'annule'
             GROUP BY c.id, c.type, c.is_vip, c.created_at"
        );
        $out = array_fill_keys(array_keys(self::SEGMENTS), []);
        $now = time();
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $out['tous'][] = $id;
            if ($r['type'] === 'pro') {
                $out['pros'][] = $id;
            }
            $idle = $r['last_order'] ? ($now - strtotime($r['last_order'])) / 86400 : null;
            $segment = match (true) {
                (int)$r['is_vip'] === 1 || (int)$r['year_spend'] >= 300_000 => 'vip',
                strtotime($r['created_at']) > $now - 30 * 86400 => 'nouveaux',
                $idle === null || $idle > 90 => 'perdus',
                $idle > 45 => 'a_risque',
                default => 'reguliers',
            };
            $out[$segment][] = $id;
        }
        return $out;
    }

    public function send(int $campaignId): int
    {
        $c = Database::one('SELECT * FROM campaigns WHERE id = ?', [$campaignId]) ?? throw new \DomainException('Campagne introuvable.');
        if ($c['status'] === 'envoyee') {
            throw new \DomainException('Campagne déjà envoyée.');
        }
        $ids = $this->segments()[$c['segment']] ?? [];
        if (!$ids) {
            throw new \DomainException('Aucun client dans ce segment.');
        }
        Database::transaction(function () use ($c, $ids, $campaignId): void {
            $in = implode(',', array_map('intval', $ids));
            foreach (Database::all("SELECT id, name, loyalty_points FROM clients WHERE id IN ($in)") as $client) {
                $first = explode(' ', trim($client['name']))[0];
                $body = strtr($c['message'], ['{prenom}' => $first, '{nom}' => $client['name'], '{points}' => (string)$client['loyalty_points']]);
                Notifier::queue((int)$client['id'], $body, $c['channel'] === 'auto' ? null : $c['channel'], $campaignId);
            }
            Database::update('campaigns', ['status' => 'envoyee', 'sent_count' => count($ids), 'sent_at' => now()], 'id = :id', ['id' => $campaignId]);
        });
        Audit::log('campaign.send', 'campaigns', $campaignId, ['count' => count($ids)]);
        return count($ids);
    }

    /** Retours : clients ciblés ayant commandé dans les 14 jours suivant l'envoi. */
    public function results(int $campaignId): array
    {
        return Database::one(
            "SELECT COUNT(DISTINCT o.client_id) clients, COALESCE(SUM(o.total), 0) revenue
             FROM campaigns cp JOIN messages m ON m.campaign_id = cp.id
             JOIN orders o ON o.client_id = m.client_id AND o.status <> 'annule'
                AND o.created_at BETWEEN cp.sent_at AND cp.sent_at + INTERVAL 14 DAY
             WHERE cp.id = ?",
            [$campaignId]
        ) ?? ['clients' => 0, 'revenue' => 0];
    }
}
