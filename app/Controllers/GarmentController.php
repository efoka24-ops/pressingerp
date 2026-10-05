<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Domain\IncidentType;
use App\Services\WorkflowService;

/** Scan QR atelier : fiche pièce + actions d'étape */
final class GarmentController extends Controller
{
    public function scan(): void
    {
        $code = strtoupper($this->str('code'));
        $g = null;
        $events = [];
        $candidates = [];
        if ($code !== '') {
            $g = Database::one(
                "SELECT g.*, o.number, o.promised_at, o.service_level, o.status order_status, o.rail, c.name client,
                        (SELECT COUNT(*) FROM garments x WHERE x.order_id = o.id) total_pcs,
                        (SELECT COUNT(*) FROM garments x WHERE x.order_id = o.id AND x.step IN ('pret', 'retire')) ready_pcs,
                        u.name operator
                 FROM garments g JOIN orders o ON o.id = g.order_id JOIN clients c ON c.id = o.client_id LEFT JOIN users u ON u.id = g.assigned_to
                 WHERE g.code = ?",
                [$code]
            );
            if ($g) {
                $events = WorkflowService::events((int)$g['id']);
            } else {
                // QR illisible ou mal saisi : recherche par numéro de commande ou fragment de code (SE5)
                $candidates = WorkflowService::findGarments($code);
                flash('err', $candidates ? "Code $code non reconnu : choisissez la pièce ci-dessous." : "Aucune pièce pour « $code ».");
            }
        }
        $route = $g ? WorkflowService::route($g['treatment_id'] ? (int)$g['treatment_id'] : null) : [];
        $this->view('garments/scan', [
            'title' => 'Scanner une pièce', 'code' => $code, 'g' => $g, 'events' => $events, 'candidates' => $candidates,
            'route' => $route, 'next' => $g ? WorkflowService::nextStep($g) : null,
            'treatment' => $g && $g['treatment_id'] ? Database::value('SELECT label FROM treatments WHERE id = ?', [$g['treatment_id']]) : null,
            'incidents' => $g ? Database::all('SELECT i.*, u.name reporter FROM incidents i LEFT JOIN users u ON u.id = i.reported_by WHERE i.garment_id = ? ORDER BY i.id DESC LIMIT 5', [$g['id']]) : [],
        ]);
    }

    public function action(string $id): void
    {
        $gid = (int)$id;
        $wf = new WorkflowService();
        try {
            match ($this->str('do')) {
                'start'    => $wf->start($gid),
                'complete' => $wf->complete($gid, $this->str('machine') ?: null, $this->str('rail') ?: null),
                'skip'     => $wf->skip($gid),
                'block'    => $wf->block($gid, IncidentType::tryFrom($this->str('type')) ?? throw new \DomainException('Choisissez le type d\'incident.'), $this->str('note')),
                'unblock'  => $wf->unblock($gid, $this->str('resolution')),
                default    => throw new \DomainException('Action inconnue.'),
            };
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        if ($this->str('back') === 'production') {
            $this->ok('Pièce mise à jour.', '/production');
        }
        $code = (string)Database::value('SELECT code FROM garments WHERE id = ?', [$gid]);
        $this->ok('Pièce mise à jour.', '/scan?code=' . urlencode($code));
    }
}
