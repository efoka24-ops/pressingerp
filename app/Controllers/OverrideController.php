<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\QualityGate;

/** Dérogations au contrôle qualité : octroi par un responsable et registre. */
final class OverrideController extends Controller
{
    public function index(): void
    {
        $rows = Database::all(
            'SELECT q.*, g.code, g.label, o.number, c.name client, u.name authoriser
             FROM quality_overrides q JOIN garments g ON g.id = q.garment_id JOIN orders o ON o.id = g.order_id JOIN clients c ON c.id = o.client_id
             LEFT JOIN users u ON u.id = q.authorised_by ORDER BY q.id DESC LIMIT 100'
        );
        $this->view('quality/overrides', ['title' => 'Dérogations qualité', 'rows' => $rows]);
    }

    public function grant(string $id): void
    {
        try {
            (new QualityGate())->override((int)$id, $this->str('reason'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $code = (string)Database::value('SELECT code FROM garments WHERE id = ?', [(int)$id]);
        $this->ok('Dérogation accordée et tracée : la pièce passe à l\'emballage.', '/scan?code=' . urlencode($code));
    }
}
