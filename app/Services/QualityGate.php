<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\Step;

/**
 * Verrou qualité (RG8) : une pièce n'atteint l'emballage, « Prêt » ou le retrait que si son dernier contrôle est
 * « conforme », ou si un responsable a accordé une dérogation motivée postérieure à son dernier contrôle.
 * Un contrôle « à reprendre » annule toute validation antérieure : après une reprise, un nouveau contrôle est exigé.
 */
final class QualityGate
{
    /** Étapes interdites tant que le contrôle n'est pas passé. */
    public const GUARDED = ['emballage', 'pret', 'retire'];

    public static function passed(int $garmentId): bool
    {
        $last = Database::one('SELECT result, created_at FROM quality_checks WHERE garment_id = ? ORDER BY id DESC LIMIT 1', [$garmentId]);
        if ($last && $last['result'] === 'conforme') {
            return true;
        }
        $override = Database::one('SELECT created_at FROM quality_overrides WHERE garment_id = ? ORDER BY id DESC LIMIT 1', [$garmentId]);
        return $override !== null && (!$last || $override['created_at'] >= $last['created_at']);
    }

    /** Lève une exception si la pièce ne peut pas entrer à cette étape. */
    public static function assertCanEnter(int $garmentId, Step $to): void
    {
        if (in_array($to->value, self::GUARDED, true) && !self::passed($garmentId)) {
            $code = (string)Database::value('SELECT code FROM garments WHERE id = ?', [$garmentId]);
            throw new \DomainException("Contrôle qualité non validé pour $code : la pièce ne peut pas passer à « {$to->label()} » (dérogation d'un responsable requise).");
        }
    }

    /** Toutes les pièces non encore remises doivent avoir passé le contrôle (garde supplémentaire au retrait). */
    public static function assertOrderReady(int $orderId): void
    {
        foreach (Database::all("SELECT id, code FROM garments WHERE order_id = ? AND step <> 'retire'", [$orderId]) as $g) {
            if (!self::passed((int)$g['id'])) {
                throw new \DomainException("Remise impossible : la pièce {$g['code']} n'a pas de contrôle qualité conforme.");
            }
        }
    }

    /**
     * Dérogation accordée par un responsable : la pièce peut passer à l'emballage sans contrôle conforme.
     * Réservée à la direction, au responsable d'agence et à l'administrateur ; motif détaillé obligatoire ; auditée.
     */
    public function override(int $garmentId, string $reason): void
    {
        if (!Auth::isManager() || !Auth::can('quality', 'validate')) {
            throw new \DomainException('Dérogation réservée à un responsable habilité.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new \DomainException('Motif détaillé obligatoire (10 caractères minimum).');
        }
        $g = Database::one('SELECT * FROM garments WHERE id = ?', [$garmentId]) ?? throw new \DomainException('Pièce introuvable.');
        if ($g['step'] !== Step::Controle->value) {
            throw new \DomainException('Une dérogation ne s\'accorde qu\'à une pièce en attente de contrôle qualité.');
        }
        if (self::passed($garmentId)) {
            throw new \DomainException('Cette pièce a déjà un contrôle valide.');
        }
        Database::transaction(function () use ($g, $garmentId, $reason): void {
            Database::insert('quality_overrides', ['garment_id' => $garmentId, 'step' => $g['step'], 'reason' => mb_substr($reason, 0, 255), 'authorised_by' => Auth::id(), 'created_at' => now()]);
            WorkflowService::log($garmentId, Step::Controle, 'derogation', $reason);
            Audit::log('quality.override', 'garments', $garmentId, ['code' => $g['code']], ['quality' => 'non contrôlée'], ['quality' => 'dérogation'], $reason);
            (new WorkflowService())->moveTo($garmentId, Step::Emballage, \App\Domain\GarmentStatus::ATraiter, null, 'Dérogation qualité : ' . $reason);
        });
    }
}
