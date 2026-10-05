<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Domain\Step;

/**
 * Moteur d'alertes interservices. Une alerte = une situation ouverte identifiée par une clé (pas de doublon) ;
 * elle se ferme quand la situation disparaît, et monte au rôle supérieur si personne ne la prend en charge à temps.
 * `tick()` évalue toutes les situations ; il est lancé par cron (bin/alerts.php) et, à défaut, au fil des pages (`lazyTick`).
 */
final class AlertService
{
    private const LEVELS = ['normal' => 'info', 'high' => 'warn', 'critical' => 'critical'];

    /** Rôles qui voient les alertes d'un rôle cible, en plus de celui-ci. */
    private const SEES = [
        'superviseur' => ['superviseur', 'atelier', 'qualite'],
        'manager'     => ['*'],
        'direction'   => ['*'],
        'admin'       => ['*'],
    ];

    private static ?array $rules = null;

    /** Les alertes ne doivent jamais empêcher une opération métier : toute erreur est journalisée puis ignorée. */
    public static function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('Alertes : ' . $e->getMessage());
        }
    }

    public static function resetCache(): void
    {
        self::$rules = null;
    }

    public static function rule(string $event): ?array
    {
        if (self::$rules === null) {
            self::$rules = [];
            foreach (Database::all('SELECT * FROM alert_rules') as $r) {
                self::$rules[$r['event']] = $r;
            }
        }
        return self::$rules[$event] ?? null;
    }

    /** Ouvre l'alerte, ou met à jour son message si elle est déjà ouverte. @return ?int null si la règle est désactivée */
    public static function raise(string $event, string $key, string $message, ?string $subjectType = null, ?int $subjectId = null, ?int $agencyId = null, ?string $targetRole = null, ?int $now = null): ?int
    {
        $rule = self::rule($event);
        if (!$rule || !(int)$rule['active']) {
            return null;
        }
        $message = mb_substr($message, 0, 255);
        $open = Database::one('SELECT id, message FROM alerts WHERE dedupe_key = ? AND closed_at IS NULL', [$key]);
        if ($open) {
            if ($open['message'] !== $message) {
                Database::update('alerts', ['message' => $message], 'id = :id', ['id' => $open['id']]);
            }
            return (int)$open['id'];
        }
        return Database::insert('alerts', [
            'dedupe_key' => $key, 'event' => $event, 'level' => self::LEVELS[$rule['priority']] ?? 'info', 'message' => $message,
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'agency_id' => $agencyId,
            'target_role' => $targetRole ?? $rule['target_role'], 'opened_at' => date('Y-m-d H:i:s', $now ?? time()),
        ]);
    }

    public static function close(string $key, string $reason = 'situation résolue', ?int $now = null): int
    {
        return Database::run('UPDATE alerts SET closed_at = ?, close_reason = ? WHERE dedupe_key = ? AND closed_at IS NULL', [date('Y-m-d H:i:s', $now ?? time()), $reason, $key])->rowCount();
    }

    /** Prise en compte par un utilisateur autorisé à la voir ; les alertes sans situation à résoudre (écart de caisse) se ferment. */
    public static function acknowledge(int $id): void
    {
        $a = Database::one('SELECT * FROM alerts WHERE id = ? AND closed_at IS NULL', [$id]) ?? throw new \DomainException('Alerte introuvable ou déjà fermée.');
        if (!self::canSee((array)Auth::user(), $a)) {
            throw new \DomainException('Cette alerte ne vous est pas destinée.');
        }
        $closes = $a['event'] === 'cash_variance';
        Database::update('alerts', ['ack_by' => Auth::id() ?: null, 'ack_at' => now()] + ($closes ? ['closed_at' => now(), 'close_reason' => 'prise en compte par ' . (string)(Auth::user()['name'] ?? '')] : []), 'id = :id', ['id' => $id]);
        Audit::log('alert.ack', 'alerts', $id, ['event' => $a['event'], 'key' => $a['dedupe_key']]);
    }

    public static function canSee(array $user, array $alert): bool
    {
        $role = (string)($user['role'] ?? '');
        if ($role === '') {
            return false;
        }
        $roles = self::SEES[$role] ?? [$role];
        $byRole = in_array('*', $roles, true) || in_array($alert['target_role'], $roles, true) || ($alert['escalated_role'] && in_array($alert['escalated_role'], $roles, true));
        if (!$byRole) {
            return false;
        }
        // Un rôle limité à son agence ne voit que les alertes de son agence (ou communes à toutes)
        $scoped = in_array($role, ['comptoir', 'manager'], true);
        return !$scoped || $alert['agency_id'] === null || (int)$alert['agency_id'] === (int)$user['agency_id'];
    }

    /** @return list<array> alertes ouvertes visibles par cet utilisateur, les plus graves d'abord */
    public static function visible(array $user): array
    {
        $rows = Database::all(
            "SELECT * FROM alerts WHERE closed_at IS NULL ORDER BY FIELD(level, 'critical', 'warn', 'info'), (escalated_at IS NULL), opened_at"
        );
        return array_values(array_filter($rows, fn($a) => self::canSee($user, $a)));
    }

    public static function count(array $user): int
    {
        return count(array_filter(self::visible($user), fn($a) => !$a['ack_at']));
    }

    /** Évalue toutes les situations. Retourne le nombre d'alertes ouvertes par événement après évaluation. */
    public static function tick(?int $now = null): array
    {
        $now ??= time();
        $at = fn(int $minutesAgo) => date('Y-m-d H:i:s', $now - $minutesAgo * 60);
        $agencyOf = fn($row) => isset($row['agency_id']) ? (int)$row['agency_id'] : null;

        // Fin de traitement : pièces disponibles à chaque poste
        foreach (Step::production() as $step) {
            if ($step !== Step::Pret) {
                self::refreshTransfer($step, $now);
            }
        }

        // Pièces non prises en charge dans le délai paramétré
        $delay = (int)(self::rule('stale')['delay_minutes'] ?? 120);
        $wanted = [];
        foreach (Database::all(
            "SELECT g.id, g.code, g.step, g.step_since, o.agency_id FROM garments g JOIN orders o ON o.id = g.order_id
             WHERE g.status IN ('a_traiter', 'a_reprendre') AND g.step IN ('tri','detachage','lavage','sechage','repassage','finition','controle','emballage')
               AND g.step_since <= ? AND o.status = 'en_atelier'", [$at($delay)]) as $g) {
            $mins = (int)floor(($now - strtotime($g['step_since'])) / 60);
            $wanted['stale:' . $g['id']] = ["Pièce {$g['code']} non prise en charge depuis " . self::duration($mins) . ' à « ' . Step::from($g['step'])->label() . ' »', 'garment', (int)$g['id'], null, $g['step'] === 'controle' ? 'qualite' : null];
        }
        self::sync('stale', $wanted, $now);

        // Pièces bloquées par un incident
        $delay = (int)(self::rule('blocked')['delay_minutes'] ?? 0);
        $wanted = [];
        foreach (Database::all(
            "SELECT g.id, g.code, g.step, g.updated_at, i.type, i.note FROM garments g
             LEFT JOIN incidents i ON i.garment_id = g.id AND i.resolved_at IS NULL
             WHERE g.status = 'bloque' AND g.updated_at <= ? GROUP BY g.id, g.code, g.step, g.updated_at, i.type, i.note", [$at($delay)]) as $g) {
            $wanted['blocked:' . $g['id']] = ["Pièce {$g['code']} bloquée à « " . Step::from($g['step'])->label() . ' »' . ($g['note'] ? ' : ' . $g['note'] : ''), 'garment', (int)$g['id'], null, null];
        }
        self::sync('blocked', $wanted, $now);

        // Incidents critiques : au manager, tout de suite
        $wanted = [];
        foreach (Database::all("SELECT i.id, i.garment_id, i.note, g.code FROM incidents i JOIN garments g ON g.id = i.garment_id WHERE i.severity = 'critical' AND i.resolved_at IS NULL") as $i) {
            $wanted['incident:' . $i['id']] = ["Incident critique sur {$i['code']} : {$i['note']}", 'garment', (int)$i['garment_id'], null, null];
        }
        self::sync('incident_critical', $wanted, $now);

        // Reprises qualité : retour au service responsable avec le motif
        $wanted = [];
        foreach (Database::all(
            "SELECT g.id, g.code, g.step, (SELECT reason FROM quality_checks q WHERE q.garment_id = g.id AND q.result = 'reprise' ORDER BY q.id DESC LIMIT 1) reason
             FROM garments g WHERE g.status = 'a_reprendre'") as $g) {
            $wanted['rework:' . $g['id']] = ["Reprise qualité : {$g['code']} revient à « " . Step::from($g['step'])->label() . ' »' . ($g['reason'] ? ' (' . $g['reason'] . ')' : ''), 'garment', (int)$g['id'], null, null];
        }
        self::sync('rework', $wanted, $now);

        // Retards (rouge) et risques de retard (orange) comparés à la date promise
        $orange = (int)Config::get('risk_orange_hours', 3);
        $late = $risk = [];
        $lateOrders = [];
        foreach (Database::all("SELECT o.id, o.number, o.agency_id, o.promised_at, o.client_id, o.tracking_token, c.name FROM orders o JOIN clients c ON c.id = o.client_id WHERE o.status = 'en_atelier'") as $o) {
            $t = strtotime($o['promised_at']);
            if ($t < $now) {
                $lateOrders[] = $o;
                $late['late:' . $o['id']] = ["Commande {$o['number']} ({$o['name']}) en retard de " . self::duration((int)floor(($now - $t) / 60)), 'order', (int)$o['id'], (int)$o['agency_id'], null];
            } elseif ($t < $now + $orange * 3600) {
                $risk['risk:' . $o['id']] = ["Commande {$o['number']} ({$o['name']}) : à rendre dans " . self::duration((int)floor(($t - $now) / 60)) . ', pas encore prête', 'order', (int)$o['id'], (int)$o['agency_id'], null];
            }
        }
        self::sync('order_late', $late, $now);
        self::sync('order_risk', $risk, $now);

        // Le client est prévenu une seule fois du retard, avec un nouveau délai estimé (CdC §11)
        foreach ($lateOrders as $o) {
            self::safe(fn() => MessageService::queueEvent('late', (int)$o['client_id'], [
                'numero' => $o['number'], 'date_promise' => date('d/m à H:i', strtotime(\App\Domain\ServiceLevel::Express->promisedAt($now))), 'lien' => tracking_url((string)$o['tracking_token']),
            ], (int)$o['id'], true));
        }

        // Messages qui ne partent pas faute de canal configuré
        $stuck = MessageService::stuckCount($now);
        self::sync('messages_stuck', $stuck > 0 ? ['msgstuck' => ["$stuck message(s) client en attente : aucun canal d'envoi n'est configuré (SMS, WhatsApp). Configurer le fournisseur dans config.local.php.", null, null, null, null]] : [], $now);

        // Livraisons et collectes sans livreur dans le délai de la règle
        $delay = (int)(self::rule('delivery_unassigned')['delay_minutes'] ?? 60);
        $wanted = [];
        foreach (Database::all(
            "SELECT d.id, d.kind, d.agency_id, d.address, o.number FROM deliveries d LEFT JOIN orders o ON o.id = d.order_id
             WHERE d.driver_id IS NULL AND d.status IN ('a_collecter', 'a_livrer') AND d.created_at <= ?", [$at($delay)]) as $d) {
            $wanted['dlv:' . $d['id']] = [($d['kind'] === 'collect' ? 'Collecte' : 'Livraison ' . $d['number']) . " sans livreur ({$d['address']}) : affecter.", 'delivery', (int)$d['id'], (int)$d['agency_id'], null];
        }
        self::sync('delivery_unassigned', $wanted, $now);

        // Livraisons non abouties à replanifier (créneau dépassé sans nouvelle tentative, ou 3 échecs)
        $wanted = [];
        foreach (Database::all(
            "SELECT d.id, d.agency_id, d.attempts, o.number FROM deliveries d JOIN orders o ON o.id = d.order_id WHERE d.status = 'non_livre'") as $d) {
            $wanted['dlvfail:' . $d['id']] = ["Livraison de {$d['number']} non aboutie, tentative " . (int)$d['attempts'] . '/' . DeliveryService::MAX_ATTEMPTS . ($d['attempts'] >= DeliveryService::MAX_ATTEMPTS ? ' : contacter le client, envisager le retrait en agence.' : ' : à replanifier.'), 'delivery', (int)$d['id'], (int)$d['agency_id'], null];
        }
        self::sync('delivery_failed', $wanted, $now);

        // Encours client au-dessus du plafond
        $wanted = [];
        foreach (Database::all("SELECT id, name, credit_limit FROM clients WHERE type = 'pro' AND credit_limit > 0") as $c) {
            $out = ClientService::outstanding((int)$c['id']);
            if ($out > (int)$c['credit_limit']) {
                $wanted['credit:' . $c['id']] = ["Encours de {$c['name']} : " . money($out) . ' FCFA pour un plafond de ' . money($c['credit_limit']), 'client', (int)$c['id'], null, null];
            }
        }
        self::sync('credit_over', $wanted, $now);

        // Stock critique
        $wanted = [];
        foreach (Database::all('SELECT id, name, quantity, min_qty, unit FROM stock_items WHERE quantity < min_qty') as $s) {
            $wanted['stock:' . $s['id']] = ["Stock critique : {$s['name']} ({$s['quantity']} {$s['unit']} pour un minimum de {$s['min_qty']})", 'stock', (int)$s['id'], null, null];
        }
        self::sync('stock_low', $wanted, $now);

        self::escalate($now);
        $out = [];
        foreach (Database::all('SELECT event, COUNT(*) n FROM alerts WHERE closed_at IS NULL GROUP BY event') as $r) {
            $out[$r['event']] = (int)$r['n'];
        }
        return $out;
    }

    /** Fin de traitement : compte les pièces disponibles à ce poste et alerte le service concerné. */
    public static function refreshTransfer(Step $step, ?int $now = null): void
    {
        $n = (int)Database::value(
            "SELECT COUNT(*) FROM garments g JOIN orders o ON o.id = g.order_id WHERE g.step = ? AND g.status IN ('a_traiter', 'a_reprendre') AND o.status = 'en_atelier'",
            [$step->value]
        );
        $key = 'transfer:' . $step->value;
        if ($n > 0) {
            self::raise('transfer', $key, "$n pièce" . ($n > 1 ? 's' : '') . ' disponible' . ($n > 1 ? 's' : '') . ' à « ' . $step->label() . ' »', 'step', null, null, $step === Step::Controle ? 'qualite' : 'atelier', $now);
        } else {
            self::close($key, 'plus de pièce en attente', $now);
        }
    }

    /** Ouvre les alertes voulues, ferme celles de l'événement qui ne le sont plus. @param array<string,array> $wanted clé => [message, type, id, agence, rôle] */
    private static function sync(string $event, array $wanted, int $now): void
    {
        foreach ($wanted as $key => [$message, $type, $id, $agency, $role]) {
            self::raise($event, $key, $message, $type, $id, $agency, $role, $now);
        }
        foreach (Database::all('SELECT dedupe_key FROM alerts WHERE event = ? AND closed_at IS NULL', [$event]) as $open) {
            if (!isset($wanted[$open['dedupe_key']])) {
                self::close($open['dedupe_key'], 'situation résolue', $now);
            }
        }
    }

    /** Une alerte non prise en compte à temps monte au rôle supérieur défini par sa règle. */
    private static function escalate(int $now): void
    {
        foreach (Database::all('SELECT a.id, a.event, a.opened_at, r.escalate_role, r.escalate_after_minutes FROM alerts a JOIN alert_rules r ON r.event = a.event
                                WHERE a.closed_at IS NULL AND a.escalated_at IS NULL AND a.ack_at IS NULL AND r.escalate_role IS NOT NULL AND r.escalate_after_minutes IS NOT NULL') as $a) {
            if ($now - strtotime($a['opened_at']) >= (int)$a['escalate_after_minutes'] * 60) {
                Database::update('alerts', ['escalated_at' => date('Y-m-d H:i:s', $now), 'escalated_role' => $a['escalate_role'], 'level' => 'critical'], 'id = :id', ['id' => $a['id']]);
            }
        }
    }

    /** Évalue au plus une fois par minute, au fil des pages, quand aucun cron n'est configuré. Ne casse jamais une page. */
    public static function lazyTick(): void
    {
        try {
            $file = BASE_PATH . '/storage/alerts.tick';
            if (is_file($file) && time() - (int)filemtime($file) < 60) {
                return;
            }
            @mkdir(dirname($file), 0750, true);
            @touch($file);
            self::tick();
        } catch (\Throwable $e) {
            error_log('Alertes : ' . $e->getMessage());
        }
    }

    private static function duration(int $minutes): string
    {
        return $minutes >= 120 ? intdiv($minutes, 60) . ' h' : max(0, $minutes) . ' min';
    }
}
