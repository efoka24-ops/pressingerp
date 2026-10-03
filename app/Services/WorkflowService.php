<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\GarmentStatus;
use App\Domain\Step;

/** Machine à états des pièces : prise en charge, fin d'étape, incident, saut d'étape. */
final class WorkflowService
{
    public function garment(int $id): array
    {
        return Database::one('SELECT * FROM garments WHERE id = ?', [$id]) ?? throw new \DomainException('Pièce introuvable.');
    }

    public function start(int $id): void
    {
        $g = $this->garment($id);
        $status = GarmentStatus::from($g['status']);
        if (!in_array($status, [GarmentStatus::ATraiter, GarmentStatus::AReprendre], true)) {
            throw new \DomainException('Pièce déjà prise en charge, bloquée ou terminée.');
        }
        $this->guardWorkshopStep($g);
        Database::update('garments', ['status' => GarmentStatus::EnCours->value, 'assigned_to' => Auth::id() ?: null, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        self::log($id, Step::from($g['step']), 'prise_en_charge');
    }

    public function complete(int $id, ?string $machine = null, ?string $rail = null): void
    {
        $g = $this->garment($id);
        $step = Step::from($g['step']);
        $this->guardWorkshopStep($g);
        if ($g['status'] === GarmentStatus::Bloque->value) {
            throw new \DomainException('Pièce bloquée : levez l\'incident d\'abord.');
        }
        if ($step === Step::Emballage && ($rail === null || $rail === '')) {
            throw new \DomainException('Indiquez l\'emplacement de rangement (rail).');
        }
        self::log($id, $step, 'termine', null, $machine);
        $this->moveTo($id, $step->next(), GarmentStatus::ATraiter, $rail);
    }

    /** Étape non nécessaire pour cette pièce (ex. pas de tache → pas de détachage). */
    public function skip(int $id): void
    {
        $g = $this->garment($id);
        $step = Step::from($g['step']);
        if (!in_array($step, [Step::Detachage, Step::Sechage, Step::Finition], true)) {
            throw new \DomainException('Cette étape ne peut pas être sautée.');
        }
        self::log($id, $step, 'non_applicable');
        $this->moveTo($id, $step->next(), GarmentStatus::ATraiter);
    }

    public function block(int $id, string $note): void
    {
        $g = $this->garment($id);
        if (trim($note) === '') {
            throw new \DomainException('Décrivez l\'incident.');
        }
        Database::update('garments', ['status' => GarmentStatus::Bloque->value, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        self::log($id, Step::from($g['step']), 'incident', $note);
    }

    public function unblock(int $id): void
    {
        $g = $this->garment($id);
        if ($g['status'] !== GarmentStatus::Bloque->value) {
            throw new \DomainException('Pièce non bloquée.');
        }
        Database::update('garments', ['status' => GarmentStatus::ATraiter->value, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        self::log($id, Step::from($g['step']), 'incident_leve');
    }

    public function moveTo(int $id, Step $to, GarmentStatus $status, ?string $rail = null, ?string $note = null): void
    {
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
    }

    public static function log(int $garmentId, Step $step, string $action, ?string $note = null, ?string $machine = null): void
    {
        Database::insert('garment_events', [
            'garment_id' => $garmentId,
            'step'       => $step->value,
            'action'     => $action,
            'note'       => $note,
            'machine'    => $machine,
            'user_id'    => Auth::id() ?: null,
            'created_at' => now(),
        ]);
    }

    public static function events(int $garmentId): array
    {
        return Database::all(
            'SELECT e.*, u.name AS user FROM garment_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.garment_id = ? ORDER BY e.created_at DESC, e.id DESC',
            [$garmentId]
        );
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
