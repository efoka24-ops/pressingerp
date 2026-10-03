<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\WorkflowService;

/** Scan QR atelier : fiche pièce + actions d'étape */
final class GarmentController extends Controller
{
    public function scan(): void
    {
        $code = strtoupper($this->str('code'));
        $g = null;
        $events = [];
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
                flash('err', "Aucune pièce pour le code $code.");
            }
        }
        $this->view('garments/scan', ['title' => 'Scanner une pièce', 'code' => $code, 'g' => $g, 'events' => $events]);
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
                'block'    => $wf->block($gid, $this->str('note')),
                'unblock'  => $wf->unblock($gid),
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
