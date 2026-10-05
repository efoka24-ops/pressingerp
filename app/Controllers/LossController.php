<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\LossService;

/** Sinistres : pièces perdues ou endommagées et décisions d'indemnisation. */
final class LossController extends Controller
{
    public function index(): void
    {
        $rows = Database::all(
            'SELECT l.*, g.code, g.label, g.price, o.number, c.name client, d.name declared_name, m.name decided_name
             FROM garment_losses l JOIN garments g ON g.id = l.garment_id JOIN orders o ON o.id = g.order_id JOIN clients c ON c.id = o.client_id
             LEFT JOIN users d ON d.id = l.declared_by LEFT JOIN users m ON m.id = l.decided_by
             ORDER BY l.status = \'declaree\' DESC, l.id DESC LIMIT 100'
        );
        foreach ($rows as &$r) {
            $r['cap'] = LossService::cap((int)$r['garment_id']);
        }
        unset($r);
        $this->view('quality/losses', ['title' => 'Sinistres', 'rows' => $rows, 'kinds' => LossService::KINDS]);
    }

    /** Déclaration depuis la fiche pièce (atelier, superviseur, qualité). */
    public function declare(string $id): void
    {
        try {
            (new LossService())->declare((int)$id, $this->str('kind'), $this->str('description'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $code = (string)Database::value('SELECT code FROM garments WHERE id = ?', [(int)$id]);
        $this->ok('Sinistre déclaré. Un responsable doit se prononcer sur l\'indemnisation.', '/scan?code=' . urlencode($code));
    }

    public function decide(string $id): void
    {
        try {
            (new LossService())->decide((int)$id, $this->str('decision') === 'accepter', $this->int('amount'), $this->str('reason'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Décision enregistrée.', '/qualite/sinistres');
    }
}
