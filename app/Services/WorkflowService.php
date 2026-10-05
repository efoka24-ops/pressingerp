<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\GarmentStatus;
use App\Domain\IncidentType;
use App\Domain\Step;

/** Machine à états des pièces : prise en charge, fin d'étape, incident, saut d'étape, parcours par traitement. */
final class WorkflowService
{
    /** Étapes de travail d'un parcours complet (entre le tri et le contrôle qualité). */
    public const WORK_STEPS = ['detachage', 'lavage', 'sechage', 'repassage', 'finition'];

    public function garment(int $id): array
    {
        return Database::one('SELECT * FROM garments WHERE id = ?', [$id]) ?? throw new \DomainException('Pièce introuvable.');
    }

    /**
     * Parcours prévu pour une pièce : tri, étapes de travail du traitement, puis contrôle, emballage, prêt, retiré.
     * Sans traitement connu, parcours complet.
     * @return list<Step>
     */
    public static function route(?int $treatmentId): array
    {
        $csv = $treatmentId ? Database::value('SELECT steps FROM treatments WHERE id = ? AND active = 1', [$treatmentId]) : null;
        $work = $csv ? array_map(fn($s) => Step::from($s), array_filter(explode(',', (string)$csv))) : array_map(fn($s) => Step::from($s), self::WORK_STEPS);
        return [Step::Tri, ...$work, Step::Controle, Step::Emballage, Step::Pret, Step::Retire];
    }

    /** Étape suivante : la première du parcours située après l'étape courante (gère aussi les reprises vers une étape hors parcours). */
    public static function nextStep(array $garment): ?Step
    {
        $current = Step::from($garment['step']);
        foreach (self::route(isset($garment['treatment_id']) ? (int)$garment['treatment_id'] : null) as $s) {
            if ($s->index() > $current->index()) {
                return $s;
            }
        }
        return null;
    }

    public function start(int $id): void
    {
        $g = $this->garment($id);
        $status = GarmentStatus::from($g['status']);
        if (!in_array($status, [GarmentStatus::ATraiter, GarmentStatus::AReprendre], true)) {
            throw new \DomainException('Pièce déjà prise en charge, bloquée ou terminée.');
        }
        $this->guardWorkshopStep($g);
        Database::transaction(function () use ($id, $g): void {
            Database::update('garments', ['status' => GarmentStatus::EnCours->value, 'assigned_to' => Auth::id() ?: null, 'updated_at' => now()], 'id = :id', ['id' => $id]);
            self::log($id, Step::from($g['step']), 'prise_en_charge');
        });
        AlertService::safe(function () use ($id, $g): void {
            AlertService::close('stale:' . $id, 'prise en charge');
            AlertService::close('rework:' . $id, 'prise en charge');
            AlertService::refreshTransfer(Step::from($g['step']));
        });
    }

    public function complete(int $id, ?string $machine = null, ?string $rail = null): void
    {
        $g = $this->garment($id);
        $step = Step::from($g['step']);
        $this->guardWorkshopStep($g);
        $status = GarmentStatus::from($g['status']);
        if ($status === GarmentStatus::Bloque) {
            throw new \DomainException('Pièce bloquée : levez l\'incident d\'abord.');
        }
        if ($step === Step::Emballage && ($rail === null || $rail === '')) {
            throw new \DomainException('Indiquez l\'emplacement de rangement (rail).');
        }
        if (in_array($status, [GarmentStatus::ATraiter, GarmentStatus::AReprendre], true)) {
            // Terminer en un geste reste possible, mais la prise en charge est toujours tracée (opérateur + heure)
            $this->start($id);
            $g = $this->garment($id);
        } elseif ($g['assigned_to'] && (int)$g['assigned_to'] !== Auth::id() && !Auth::can('production', 'validate')) {
            $who = (string)Database::value('SELECT name FROM users WHERE id = ?', [$g['assigned_to']]);
            throw new \DomainException("Pièce prise en charge par $who : seul un superviseur peut la terminer à sa place.");
        }
        $next = self::nextStep($g) ?? throw new \DomainException('Aucune étape suivante.');
        Database::transaction(function () use ($id, $step, $machine, $next, $rail): void {
            self::log($id, $step, 'termine', null, $machine);
            $this->moveTo($id, $next, GarmentStatus::ATraiter, $rail);
        });
    }

    /** Étape non nécessaire pour cette pièce (ex. pas de tache → pas de détachage). */
    public function skip(int $id): void
    {
        $g = $this->garment($id);
        $step = Step::from($g['step']);
        if (!in_array($step, [Step::Detachage, Step::Sechage, Step::Finition], true)) {
            throw new \DomainException('Cette étape ne peut pas être sautée.');
        }
        $next = self::nextStep($g) ?? throw new \DomainException('Aucune étape suivante.');
        Database::transaction(function () use ($id, $step, $next): void {
            self::log($id, $step, 'non_applicable');
            $this->moveTo($id, $next, GarmentStatus::ATraiter);
        });
    }

    /** Signale un incident typé : la pièce est bloquée jusqu'à sa levée. */
    public function block(int $id, IncidentType $type, string $note): int
    {
        $g = $this->garment($id);
        $note = trim($note);
        if ($note === '') {
            throw new \DomainException('Décrivez l\'incident.');
        }
        if ($g['status'] === GarmentStatus::Bloque->value) {
            throw new \DomainException('Pièce déjà bloquée : levez d\'abord l\'incident en cours.');
        }
        if (in_array($g['step'], [Step::Retire->value], true)) {
            throw new \DomainException('Pièce déjà remise au client.');
        }
        Database::transaction(function () use ($id, $g, $type, $note): int {
            $incident = Database::insert('incidents', [
                'garment_id' => $id, 'step' => $g['step'], 'type' => $type->value, 'severity' => $type->critical() ? 'critical' : 'normal',
                'note' => mb_substr($note, 0, 255), 'reported_by' => Auth::id() ?: null, 'created_at' => now(),
            ]);
            Database::update('garments', ['status' => GarmentStatus::Bloque->value, 'updated_at' => now()], 'id = :id', ['id' => $id]);
            self::log($id, Step::from($g['step']), 'incident', $type->label() . ' — ' . $note);
            if ($type->critical()) {
                // RG7 : un incident critique est journalisé en priorité (l'alerte au manager arrive avec le centre d'alertes)
                Audit::log('incident.critical', 'garments', $id, ['type' => $type->value, 'code' => $g['code']], null, null, $note);
            }
            return $incident;
        });
        $incident = (int)Database::value('SELECT MAX(id) FROM incidents WHERE garment_id = ?', [$id]);
        AlertService::safe(function () use ($id, $g, $type, $note, $incident): void {
            $label = Step::from($g['step'])->label();
            AlertService::raise('blocked', 'blocked:' . $id, "Pièce {$g['code']} bloquée à « $label » : $note", 'garment', $id);
            if ($type->critical()) {
                AlertService::raise('incident_critical', 'incident:' . $incident, "Incident critique sur {$g['code']} : $note", 'garment', $id);
            }
            AlertService::refreshTransfer(Step::from($g['step']));
        });
        return $incident;
    }

    public function unblock(int $id, string $resolution = ''): void
    {
        $g = $this->garment($id);
        if ($g['status'] !== GarmentStatus::Bloque->value) {
            throw new \DomainException('Pièce non bloquée.');
        }
        Database::transaction(function () use ($id, $g, $resolution): void {
            Database::run(
                'UPDATE incidents SET resolved_at = ?, resolved_by = ?, resolution = ? WHERE garment_id = ? AND resolved_at IS NULL',
                [now(), Auth::id() ?: null, mb_substr(trim($resolution), 0, 255) ?: null, $id]
            );
            Database::update('garments', ['status' => GarmentStatus::ATraiter->value, 'updated_at' => now()], 'id = :id', ['id' => $id]);
            self::log($id, Step::from($g['step']), 'incident_leve', trim($resolution) ?: null);
        });
        AlertService::safe(function () use ($id, $g): void {
            AlertService::close('blocked:' . $id, 'incident levé');
            foreach (Database::all('SELECT id FROM incidents WHERE garment_id = ?', [$id]) as $i) {
                AlertService::close('incident:' . $i['id'], 'incident levé');
            }
            AlertService::refreshTransfer(Step::from($g['step']));
        });
    }

    public function moveTo(int $id, Step $to, GarmentStatus $status, ?string $rail = null, ?string $note = null): void
    {
        QualityGate::assertCanEnter($id, $to);   // RG8 : pas d'emballage ni de « Prêt » sans contrôle conforme ou dérogation
        Database::transaction(function () use ($id, $to, $status, $rail, $note): void {
            $final = in_array($to, [Step::Pret, Step::Retire], true) ? GarmentStatus::Termine : $status;
            Database::update('garments', [
                'step'        => $to->value,
                'status'      => $final->value,
                'assigned_to' => null,
                'step_since'  => now(),
                'updated_at'  => now(),
            ], 'id = :id', ['id' => $id]);
            self::log($id, $to, 'entree', $note);
            $orderId = (int)Database::value('SELECT order_id FROM garments WHERE id = ?', [$id]);
            if ($rail) {
                Database::update('orders', ['rail' => mb_substr($rail, 0, 10)], 'id = :id', ['id' => $orderId]);
            }
            (new OrderService())->refreshStatus($orderId);
        });
        AlertService::safe(function () use ($id, $to): void {
            AlertService::close('stale:' . $id, 'pièce passée à l\'étape suivante');
            foreach (Step::production() as $s) {
                if ($s !== Step::Pret) {
                    AlertService::refreshTransfer($s);
                }
            }
        });
    }

    public static function log(int $garmentId, Step $step, string $action, ?string $note = null, ?string $machine = null, ?string $at = null): void
    {
        Database::insert('garment_events', [
            'garment_id' => $garmentId,
            'step'       => $step->value,
            'action'     => $action,
            'note'       => $note,
            'machine'    => $machine,
            'user_id'    => Auth::id() ?: null,
            'created_at' => $at ?? now(),
        ]);
    }

    public static function events(int $garmentId): array
    {
        return Database::all(
            'SELECT e.*, u.name AS user FROM garment_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.garment_id = ? ORDER BY e.created_at DESC, e.id DESC',
            [$garmentId]
        );
    }

    /**
     * Recherche manuelle quand le QR est illisible (SE5) : code exact, numéro de commande, ou fragment de code.
     * @return list<array> pièces candidates (la première est la correspondance exacte s'il y en a une)
     */
    public static function findGarments(string $q): array
    {
        $q = strtoupper(trim($q));
        if (mb_strlen($q) < 3) {
            return [];
        }
        $sql = "SELECT g.id, g.code, g.label, g.step, g.status, o.number, c.name client
                FROM garments g JOIN orders o ON o.id = g.order_id JOIN clients c ON c.id = o.client_id";
        $exact = Database::all("$sql WHERE g.code = ?", [$q]);
        if ($exact) {
            return $exact;
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        return Database::all("$sql WHERE o.number = ? OR g.code LIKE ? ORDER BY g.id DESC LIMIT 12", [$q, $like]);
    }

    private function guardWorkshopStep(array $g): void
    {
        $step = Step::from($g['step']);
        if ($step === Step::Controle) {
            throw new \DomainException('Le contrôle qualité se valide depuis le module Qualité.');
        }
        if (in_array($step, [Step::Reception, Step::Pret, Step::Retire], true)) {
            throw new \DomainException('Aucune action atelier possible à l\'étape « ' . $step->label() . ' ».');
        }
    }
}
