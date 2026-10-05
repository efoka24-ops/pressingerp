<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Scénarios marketing automatiques (réactivation, fidélité, VIP). Chaque scénario vise un segment, envoie un message modifiable
 * et ne relance pas un même client avant la fin de son délai. Ce sont des messages promotionnels : ils ne partent que vers les
 * canaux pour lesquels le client a donné son consentement (RG18) — le consentement est vérifié par la messagerie, pas ici.
 */
final class ScenarioService
{
    /**
     * Passage quotidien : passe en VIP les clients qui atteignent le seuil, puis déroule les scénarios actifs.
     * @return array{vip:int,queued:int,skipped:int,scenarios:array<string,int>}
     */
    public static function run(?int $now = null): array
    {
        $now ??= time();
        $out = ['vip' => self::promoteVip(), 'queued' => 0, 'skipped' => 0, 'scenarios' => []];
        $segments = (new MarketingService())->segments($now);
        foreach (Database::all('SELECT * FROM marketing_scenarios WHERE active = 1 ORDER BY code') as $sc) {
            $sent = 0;
            $since = date('Y-m-d H:i:s', $now - (int)$sc['cooldown_days'] * 86400);
            foreach ($segments[$sc['segment']] ?? [] as $clientId) {
                if (Database::value('SELECT id FROM scenario_runs WHERE scenario = ? AND client_id = ? AND created_at > ? LIMIT 1', [$sc['code'], $clientId, $since])) {
                    continue;
                }
                $c = Database::one('SELECT id, name, loyalty_points FROM clients WHERE id = ?', [$clientId]);
                $body = strtr($sc['body'], ['{prenom}' => explode(' ', trim($c['name']))[0], '{nom}' => $c['name'], '{points}' => (string)$c['loyalty_points']]);
                $messageId = MessageService::queueText($clientId, $body, 'marketing', null, null, 'scenario_' . $sc['code']);
                // Sans consentement : aucun message, mais le client n'est pas réexaminé chaque jour avant la fin du délai
                Database::insert('scenario_runs', ['scenario' => $sc['code'], 'client_id' => $clientId, 'message_id' => $messageId, 'created_at' => date('Y-m-d H:i:s', $now)]);
                $messageId ? $sent++ : $out['skipped']++;
            }
            $out['scenarios'][$sc['code']] = $sent;
            $out['queued'] += $sent;
            if ($sent > 0 || $out['skipped'] > 0) {
                Audit::log('scenario.run', 'marketing_scenarios', null, ['scenario' => $sc['code'], 'envoyés' => $sent]);
            }
        }
        return $out;
    }

    /** Un client qui atteint le seuil annuel devient VIP (jamais l'inverse : un VIP marqué à la main le reste). */
    public static function promoteVip(): int
    {
        $threshold = (int)SettingsService::get('vip.annual_threshold');
        if ($threshold <= 0) {
            return 0;
        }
        $n = 0;
        foreach (Database::all(
            "SELECT c.id, SUM(o.total) spend FROM clients c JOIN orders o ON o.client_id = c.id AND o.status <> 'annule' AND o.created_at > NOW() - INTERVAL 1 YEAR
             WHERE c.is_vip = 0 GROUP BY c.id HAVING SUM(o.total) >= ?", [$threshold]) as $c) {
            Database::update('clients', ['is_vip' => 1], 'id = :id', ['id' => $c['id']]);
            Audit::log('client.vip_auto', 'clients', (int)$c['id'], ['spend' => (int)$c['spend'], 'seuil' => $threshold], ['is_vip' => 0], ['is_vip' => 1]);
            $n++;
        }
        return $n;
    }

    /** Modification d'un scénario par l'équipe marketing : message, délai, activation. */
    public static function update(string $code, string $body, int $cooldown, bool $active): void
    {
        $sc = Database::one('SELECT * FROM marketing_scenarios WHERE code = ?', [$code]) ?? throw new \DomainException('Scénario introuvable.');
        $body = trim($body);
        if (mb_strlen($body) < 10 || mb_strlen($body) > 640) {
            throw new \DomainException('Message : 10 à 640 caractères.');
        }
        if ($cooldown < 7 || $cooldown > 730) {
            throw new \DomainException('Délai entre deux envois au même client : 7 à 730 jours.');
        }
        Database::update('marketing_scenarios', ['body' => $body, 'cooldown_days' => $cooldown, 'active' => $active ? 1 : 0, 'updated_by' => Auth::id() ?: null, 'updated_at' => now()], 'code = :c', ['c' => $code]);
        Audit::log('scenario.update', 'marketing_scenarios', null, ['scenario' => $code], ['active' => (int)$sc['active'], 'cooldown' => (int)$sc['cooldown_days']], ['active' => $active ? 1 : 0, 'cooldown' => $cooldown]);
    }

    /** Évalue au plus une fois toutes les six heures, au fil des pages quand aucun cron n'est configuré. Ne casse jamais une page. */
    public static function lazyRun(): void
    {
        try {
            $file = BASE_PATH . '/storage/segments.tick';
            if (is_file($file) && time() - (int)filemtime($file) < 6 * 3600) {
                return;
            }
            @mkdir(dirname($file), 0750, true);
            @touch($file);
            self::run();
        } catch (\Throwable) {
            // jamais bloquant
        }
    }
}
