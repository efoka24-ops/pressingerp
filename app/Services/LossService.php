<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\GarmentStatus;
use App\Domain\IncidentType;

/**
 * Pièces perdues ou endommagées (décision D8) : déclaration, puis décision d'indemnisation par un responsable.
 * L'indemnité est plafonnée à `compensation.max_factor` × le prix du service de la pièce.
 */
final class LossService
{
    public const KINDS = ['perdu' => 'Pièce perdue', 'endommage' => 'Pièce endommagée'];

    public function declare(int $garmentId, string $kind, string $description): int
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \DomainException('Type de sinistre invalide.');
        }
        $description = trim($description);
        if ($description === '') {
            throw new \DomainException('Décrivez les circonstances.');
        }
        $g = Database::one('SELECT id, code, status, step FROM garments WHERE id = ?', [$garmentId]) ?? throw new \DomainException('Pièce introuvable.');
        if (Database::value("SELECT COUNT(*) FROM garment_losses WHERE garment_id = ? AND status = 'declaree'", [$garmentId])) {
            throw new \DomainException('Un sinistre est déjà en attente de décision pour cette pièce.');
        }
        return Database::transaction(function () use ($g, $garmentId, $kind, $description): int {
            // La pièce est bloquée tant que le sinistre n'est pas traité (sauf si elle l'est déjà)
            if ($g['status'] !== GarmentStatus::Bloque->value) {
                (new WorkflowService())->block($garmentId, $kind === 'perdu' ? IncidentType::Autre : IncidentType::Endommage, self::KINDS[$kind] . ' — ' . $description);
            }
            $id = Database::insert('garment_losses', [
                'garment_id' => $garmentId, 'kind' => $kind, 'description' => mb_substr($description, 0, 255),
                'declared_by' => Auth::id() ?: null, 'declared_at' => now(), 'status' => 'declaree',
            ]);
            Audit::log('loss.declare', 'garments', $garmentId, ['code' => $g['code'], 'kind' => $kind], null, null, $description);
            return $id;
        });
    }

    /** Indemnité maximale pour une pièce. */
    public static function cap(int $garmentId): int
    {
        $price = (int)Database::value('SELECT price FROM garments WHERE id = ?', [$garmentId]);
        return $price * max(0, (int)SettingsService::get('compensation.max_factor'));
    }

    public function decide(int $lossId, bool $accept, int $amount, string $reason): void
    {
        $l = Database::one('SELECT * FROM garment_losses WHERE id = ?', [$lossId]) ?? throw new \DomainException('Sinistre introuvable.');
        if ($l['status'] !== 'declaree') {
            throw new \DomainException('Ce sinistre a déjà été traité.');
        }
        if (trim($reason) === '') {
            throw new \DomainException('Motif de la décision obligatoire.');
        }
        if ($accept) {
            $cap = self::cap((int)$l['garment_id']);
            if ($amount <= 0) {
                throw new \DomainException('Indiquez le montant de l\'indemnité.');
            }
            if ($amount > $cap) {
                throw new \DomainException('Indemnité supérieure au plafond (' . money($cap) . ' FCFA = ' . (int)SettingsService::get('compensation.max_factor') . ' × le prix de la pièce).');
            }
        }
        Database::transaction(function () use ($l, $accept, $amount, $reason): void {
            Database::update('garment_losses', [
                'status' => $accept ? 'acceptee' : 'refusee', 'amount' => $accept ? $amount : null,
                'decided_by' => Auth::id() ?: null, 'decided_at' => now(), 'decision_reason' => mb_substr(trim($reason), 0, 255),
            ], 'id = :id', ['id' => $l['id']]);
            Audit::log('loss.decide', 'garment_losses', (int)$l['id'], ['garment' => (int)$l['garment_id'], 'kind' => $l['kind']], ['status' => 'declaree'], ['status' => $accept ? 'acceptee' : 'refusee', 'amount' => $accept ? $amount : null], $reason);
        });
    }
}
