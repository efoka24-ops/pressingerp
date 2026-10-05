<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\GarmentStatus;
use App\Domain\Step;

final class QualityService
{
    public const CRITERIA = [
        'proprete'   => 'Propreté',
        'detachage'  => 'Détachage',
        'repassage'  => 'Repassage',
        'odeur'      => 'Odeur',
        'pliage'     => 'Pliage',
        'boutons'    => 'Boutons',
        'fermetures' => 'Fermetures',
        'integrite'  => 'Intégrité générale',
        'conformite' => 'Conformité du traitement',
    ];

    public const REASONS = ['Tache persistante', 'Auréole', 'Repassage imparfait', 'Odeur', 'Pliage', 'Couleur instable', 'Bouton / fermeture', 'Autre'];

    /** @param list<string> $failed critères non conformes */
    public function check(int $garmentId, array $failed, ?string $reason, ?Step $backTo): string
    {
        $g = Database::one('SELECT * FROM garments WHERE id = ?', [$garmentId]) ?? throw new \DomainException('Pièce introuvable.');
        if ($g['step'] !== Step::Controle->value) {
            throw new \DomainException('Cette pièce n\'est pas au contrôle qualité.');
        }
        $failed = array_values(array_intersect($failed, array_keys(self::CRITERIA)));
        $result = $failed ? 'reprise' : 'conforme';
        if ($result === 'reprise') {
            if (!$reason || !in_array($reason, self::REASONS, true)) {
                throw new \DomainException('Motif de reprise obligatoire.');
            }
            if (!$backTo || !in_array($backTo, Step::reworkTargets(), true)) {
                throw new \DomainException('Choisissez l\'étape de reprise.');
            }
        }
        $criteria = [];
        foreach (array_keys(self::CRITERIA) as $k) {
            $criteria[$k] = !in_array($k, $failed, true);
        }

        Database::transaction(function () use ($garmentId, $result, $criteria, $reason, $backTo, $g): void {
            Database::insert('quality_checks', [
                'garment_id' => $garmentId,
                'user_id'    => Auth::id() ?: null,
                'result'     => $result,
                'criteria'   => json_encode($criteria),
                'reason'     => $result === 'reprise' ? $reason : null,
                'back_to'    => $result === 'reprise' ? $backTo->value : null,
                'created_at' => now(),
            ]);
            $wf = new WorkflowService();
            if ($result === 'conforme') {
                WorkflowService::log($garmentId, Step::Controle, 'controle_ok');
                $wf->moveTo($garmentId, Step::Emballage, GarmentStatus::ATraiter);
            } else {
                Database::run('UPDATE garments SET rework_count = rework_count + 1 WHERE id = ?', [$garmentId]);
                WorkflowService::log($garmentId, Step::Controle, 'reprise', $reason . ' → ' . $backTo->label());
                $wf->moveTo($garmentId, $backTo, GarmentStatus::AReprendre, null, 'Reprise : ' . $reason);
            }
        });

        if ($result === 'reprise') {
            AlertService::safe(function () use ($garmentId, $g, $reason, $backTo): void {
                AlertService::raise('rework', 'rework:' . $garmentId, "Reprise qualité : {$g['code']} revient à « {$backTo->label()} » ($reason)", 'garment', $garmentId);
            });
        }

        return $result;
    }

    /** Taux de reprise sur N jours (en %) */
    public static function reworkRate(int $days = 7): float
    {
        $r = Database::one("SELECT COUNT(*) n, SUM(result = 'reprise') k FROM quality_checks WHERE created_at > NOW() - INTERVAL ? DAY", [$days]);
        return pct((float)($r['k'] ?? 0), (float)($r['n'] ?? 0), 1);
    }
}
