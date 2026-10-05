<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class MarketingService
{
    /** Segments principaux (un client dans un seul) puis étiquettes qui se superposent (pros, débiteurs, fort panier, points). */
    public const SEGMENTS = [
        'tous'        => 'Tous les clients',
        'vip'         => 'VIP (marqués VIP ou gros chiffre d\'affaires annuel)',
        'reguliers'   => 'Réguliers',
        'occasionnels' => 'Occasionnels',
        'nouveaux'    => 'Nouveaux',
        'a_risque'    => 'À risque (inactifs)',
        'perdus'      => 'Perdus',
        'a_verifier'  => 'À vérifier (jamais commandé)',
        'pros'        => 'Clients professionnels',
        'debiteurs'   => 'Débiteurs (solde à recouvrer)',
        'fort_panier' => 'Fort panier',
        'points'      => 'Points fidélité à utiliser',
    ];

    public const CHANNELS = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'auto' => 'Canal préféré du client'];

    /** Seuils de segmentation, paramétrables par l'administrateur (jamais figés dans le code). */
    public static function thresholds(): array
    {
        $g = fn(string $k) => (int)SettingsService::get($k);
        return [
            'vip' => $g('vip.annual_threshold'), 'new_days' => $g('seg.new_days'), 'at_risk_days' => $g('seg.at_risk_days'), 'lost_days' => $g('seg.lost_days'),
            'regular_orders' => $g('seg.regular_orders'), 'basket_high' => $g('seg.basket_high'), 'points' => $g('seg.points_notify'),
        ];
    }

    /**
     * Segment principal d'un client. Bornes : à risque dès at_risk_days sans commande, perdu au-delà de lost_days.
     * @param array{is_vip:mixed,year_spend:mixed,created_at:string,last_order:?string,orders_year:mixed} $r
     */
    public static function classify(array $r, int $now, array $th): string
    {
        $idle = $r['last_order'] ? intdiv($now - strtotime($r['last_order']), 86400) : null;
        return match (true) {
            (int)$r['is_vip'] === 1 || (int)$r['year_spend'] >= $th['vip'] => 'vip',
            intdiv($now - strtotime($r['created_at']), 86400) < $th['new_days'] => 'nouveaux',
            $idle === null => 'a_verifier',
            $idle > $th['lost_days'] => 'perdus',
            $idle >= $th['at_risk_days'] => 'a_risque',
            (int)$r['orders_year'] >= $th['regular_orders'] => 'reguliers',
            default => 'occasionnels',
        };
    }

    /** @return array<string, list<int>> segment => ids clients */
    public function segments(?int $now = null): array
    {
        $now ??= time();
        $th = self::thresholds();
        $rows = Database::all(
            "SELECT c.id, c.type, c.is_vip, c.created_at, c.loyalty_points, MAX(o.created_at) last_order,
                    COALESCE(SUM(CASE WHEN o.created_at > NOW() - INTERVAL 1 YEAR THEN o.total ELSE 0 END), 0) year_spend,
                    COALESCE(SUM(CASE WHEN o.created_at > NOW() - INTERVAL 1 YEAR THEN 1 ELSE 0 END), 0) orders_year,
                    (SELECT COALESCE(SUM(i.total - i.paid), 0) FROM invoices i WHERE i.client_id = c.id AND i.kind = 'facture' AND i.status <> 'payee')
                  + (SELECT COALESCE(SUM(x.total - x.paid), 0) FROM orders x WHERE x.client_id = c.id AND x.on_account = 1 AND x.invoice_id IS NULL AND x.status <> 'annule') debt
             FROM clients c LEFT JOIN orders o ON o.client_id = c.id AND o.status <> 'annule'
             GROUP BY c.id, c.type, c.is_vip, c.created_at, c.loyalty_points"
        );
        $out = array_fill_keys(array_keys(self::SEGMENTS), []);
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $out['tous'][] = $id;
            $out[self::classify($r, $now, $th)][] = $id;
            if ($r['type'] === 'pro') {
                $out['pros'][] = $id;
            }
            if ((int)$r['debt'] > 0) {
                $out['debiteurs'][] = $id;
            }
            if ((int)$r['orders_year'] >= 2 && intdiv((int)$r['year_spend'], max(1, (int)$r['orders_year'])) >= $th['basket_high']) {
                $out['fort_panier'][] = $id;
            }
            if ($th['points'] > 0 && (int)$r['loyalty_points'] >= $th['points']) {
                $out['points'][] = $id;
            }
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
        $queued = 0;
        Database::transaction(function () use ($c, $ids, $campaignId, &$queued): void {
            $in = implode(',', array_map('intval', $ids));
            foreach (Database::all("SELECT id, name, loyalty_points FROM clients WHERE id IN ($in)") as $client) {
                $first = explode(' ', trim($client['name']))[0];
                $body = strtr($c['message'], ['{prenom}' => $first, '{nom}' => $client['name'], '{points}' => (string)$client['loyalty_points']]);
                // Offre promotionnelle : seulement aux clients qui ont consenti à ce canal (RG18)
                if (MessageService::queueText((int)$client['id'], $body, 'marketing', $c['channel'] === 'auto' ? null : $c['channel'], $campaignId) !== null) {
                    $queued++;
                }
            }
            Database::update('campaigns', ['status' => 'envoyee', 'sent_count' => $queued, 'sent_at' => now()], 'id = :id', ['id' => $campaignId]);
        });
        Audit::log('campaign.send', 'campaigns', $campaignId, ['ciblés' => count($ids), 'envoyés' => $queued, 'sans consentement' => count($ids) - $queued]);
        return $queued;
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
