<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Relances des commandes prêtes non retirées. Les paliers (jours après « prête ») viennent du paramètre reminder.days.
 * Une relance par palier et par commande, jamais deux ; si plusieurs paliers sont dépassés d'un coup (mise en service, panne
 * de cron), seule la plus récente est envoyée et les précédentes sont marquées sautées : pas de rafale de messages.
 * Une commande remise, annulée ou en livraison à domicile n'est jamais relancée, et ses relances encore en file sont annulées.
 */
final class ReminderService
{
    public const MAX_LEVELS = 3;

    /** @return list<int> jours des paliers, croissants (au plus MAX_LEVELS) */
    public static function levels(): array
    {
        $days = array_map('intval', preg_split('/[\s,;]+/', (string)SettingsService::get('reminder.days', '2,7,15'), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $days = array_values(array_unique(array_filter($days, fn($d) => $d >= 1 && $d <= 365)));
        sort($days);
        return array_slice($days ?: [2, 7, 15], 0, self::MAX_LEVELS);
    }

    /** Jours écoulés depuis que la commande est prête (jours pleins). */
    public static function daysReady(string $readyAt, int $now): int
    {
        return max(0, intdiv($now - strtotime($readyAt), 86400));
    }

    /** @return array{queued:int,skipped:int,checked:int} */
    public static function run(?int $now = null): array
    {
        $now ??= time();
        $levels = self::levels();
        $out = ['queued' => 0, 'skipped' => 0, 'checked' => 0];
        $orders = Database::all(
            "SELECT o.*, c.name client_name FROM orders o JOIN clients c ON c.id = o.client_id
             WHERE o.status = 'pret' AND o.ready_at IS NOT NULL AND o.delivery_address IS NULL AND o.ready_at <= ?",
            [date('Y-m-d H:i:s', $now - $levels[0] * 86400)]
        );
        foreach ($orders as $o) {
            $out['checked']++;
            $days = self::daysReady($o['ready_at'], $now);
            $due = [];
            foreach ($levels as $i => $d) {
                if ($days >= $d) {
                    $due[] = $i + 1;
                }
            }
            $done = array_map('intval', array_column(Database::all('SELECT level FROM order_reminders WHERE order_id = ?', [$o['id']]), 'level'));
            $todo = array_values(array_diff($due, $done));
            if (!$todo) {
                continue;
            }
            $latest = max($todo);
            foreach ($todo as $level) {
                if ($level !== $latest) {
                    Database::insert('order_reminders', ['order_id' => $o['id'], 'level' => $level, 'created_at' => date('Y-m-d H:i:s', $now)]);
                    $out['skipped']++;
                    continue;
                }
                $balance = (int)$o['on_account'] ? 0 : max(0, (int)$o['total'] - (int)$o['paid']);
                $messageId = MessageService::queueEvent('reminder' . $level, (int)$o['client_id'], [
                    'numero' => $o['number'], 'jours' => $days,
                    'solde' => $balance > 0 ? 'Reste à payer : ' . money($balance, true) . '. ' : '',
                    'lien' => tracking_url($o['tracking_token']),
                ], (int)$o['id']);
                Database::insert('order_reminders', ['order_id' => $o['id'], 'level' => $level, 'message_id' => $messageId, 'created_at' => date('Y-m-d H:i:s', $now)]);
                $messageId ? $out['queued']++ : $out['skipped']++;
                Audit::log('reminder.queued', 'orders', (int)$o['id'], ['level' => $level, 'days' => $days]);
            }
        }
        return $out;
    }

    /** Remise ou annulation : les relances encore en file ne partiront pas. */
    public static function cancelFor(int $orderId): void
    {
        Database::run("UPDATE messages SET status = 'annule', error = 'Commande retirée ou annulée' WHERE order_id = ? AND event LIKE 'reminder%' AND status = 'en_attente'", [$orderId]);
        AlertService::safe(fn() => AlertService::close('unc:' . $orderId, 'commande retirée'));
    }

    /**
     * Commandes à signaler au responsable : prêtes depuis au moins reminder.manager_after jours.
     * Le client est dit injoignable si la dernière relance a échoué ou s'il n'a aucune coordonnée valide (SE12).
     * @return array<string,array> clé d'alerte => [message, type, id, agence, rôle]
     */
    public static function managerAlerts(int $now): array
    {
        $after = max(1, (int)SettingsService::get('reminder.manager_after', 15));
        $wanted = [];
        foreach (Database::all(
            "SELECT o.id, o.number, o.agency_id, o.ready_at, o.total, o.paid, o.on_account, o.client_id, c.name client_name, c.phone, c.email
             FROM orders o JOIN clients c ON c.id = o.client_id
             WHERE o.status = 'pret' AND o.delivery_address IS NULL AND o.ready_at <= ?", [date('Y-m-d H:i:s', $now - $after * 86400)]) as $o) {
            $days = self::daysReady($o['ready_at'], $now);
            $due = (int)$o['on_account'] ? 0 : max(0, (int)$o['total'] - (int)$o['paid']);
            $failed = (int)Database::value("SELECT COUNT(*) FROM messages WHERE order_id = ? AND event LIKE 'reminder%' AND status = 'echec'", [$o['id']]);
            $reachable = MessageService::chain(['id' => $o['client_id'], 'phone' => $o['phone'], 'email' => $o['email'], 'preferred_channel' => 'sms'], 'operational') !== [];
            $wanted['unc:' . $o['id']] = [
                "Commande {$o['number']} ({$o['client_name']}) prête depuis $days jours, non retirée" . ($due > 0 ? ' · ' . money($due) . ' FCFA à encaisser' : '') . '.'
                    . ($failed > 0 || !$reachable ? ' Client injoignable par message : l\'appeler ou le visiter.' : ' Contacter le client.'),
                'order', (int)$o['id'], (int)$o['agency_id'], null,
            ];
        }
        return $wanted;
    }

    /**
     * Tableau de bord des non retirés : nombre, valeur et ancienneté par tranche.
     * @return array{buckets:list<array>,orders:list<array>}
     */
    public static function overview(int $agencyId, ?int $now = null): array
    {
        $now ??= time();
        $rows = Database::all(
            "SELECT o.id, o.number, o.total, o.paid, o.on_account, o.ready_at, o.agency_id, c.name client, c.phone,
                    (SELECT MAX(level) FROM order_reminders r WHERE r.order_id = o.id AND r.message_id IS NOT NULL) last_level
             FROM orders o JOIN clients c ON c.id = o.client_id
             WHERE o.status = 'pret' AND o.ready_at IS NOT NULL" . ($agencyId ? ' AND o.agency_id = ' . $agencyId : '') . ' ORDER BY o.ready_at'
        );
        $edges = [[0, 2, '0 à 2 jours'], [3, 7, '3 à 7 jours'], [8, 15, '8 à 15 jours'], [16, PHP_INT_MAX, 'Plus de 15 jours']];
        $buckets = array_map(fn($e) => ['label' => $e[2], 'from' => $e[0], 'to' => $e[1], 'n' => 0, 'value' => 0, 'due' => 0], $edges);
        foreach ($rows as &$r) {
            $r['days'] = self::daysReady($r['ready_at'], $now);
            $r['due'] = (int)$r['on_account'] ? 0 : max(0, (int)$r['total'] - (int)$r['paid']);
            foreach ($buckets as &$b) {
                if ($r['days'] >= $b['from'] && $r['days'] <= $b['to']) {
                    $b['n']++;
                    $b['value'] += (int)$r['total'];
                    $b['due'] += $r['due'];
                    break;
                }
            }
            unset($b);
        }
        unset($r);
        return ['buckets' => $buckets, 'orders' => $rows];
    }
}
