<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Services\AlertService;

/** Centre d'alertes : alertes ouvertes visibles par le profil, commandes à risque, prise en compte. */
final class AlertController extends Controller
{
    public function index(): void
    {
        $user = (array)Auth::user();
        $alerts = AlertService::visible($user);
        $codes = [];
        $ids = array_map(fn($a) => (int)$a['subject_id'], array_filter($alerts, fn($a) => $a['subject_type'] === 'garment'));
        if ($ids) {
            foreach (Database::all('SELECT id, code FROM garments WHERE id IN (' . implode(',', array_unique($ids)) . ')') as $g) {
                $codes[(int)$g['id']] = $g['code'];
            }
        }
        $scope = Auth::scopedAgencyId();
        $risky = [];
        foreach (Database::all(
            "SELECT o.id, o.number, o.promised_at, o.status, c.name client,
                    (SELECT COUNT(*) FROM garments x WHERE x.order_id = o.id) pcs
             FROM orders o JOIN clients c ON c.id = o.client_id
             WHERE o.status = 'en_atelier'" . ($scope ? " AND o.agency_id = $scope" : '') . ' ORDER BY o.promised_at LIMIT 200'
        ) as $o) {
            $r = risk($o['promised_at'], $o['status']);
            if (!in_array($r, ['red', 'orange'], true)) {
                continue;
            }
            // Étape bloquante : la pièce la moins avancée, avec son opérateur éventuel
            $g = Database::one(
                "SELECT g.step, g.step_since, u.name operator FROM garments g LEFT JOIN users u ON u.id = g.assigned_to
                 WHERE g.order_id = ? AND g.step NOT IN ('pret', 'retire') ORDER BY FIELD(g.step, 'reception','tri','detachage','lavage','sechage','repassage','finition','controle','emballage'), g.step_since LIMIT 1",
                [$o['id']]
            );
            if ($g) {
                $risky[] = $o + $g + ['risk' => $r];
            }
        }
        usort($risky, fn($a, $b) => ($a['risk'] === 'red' ? 0 : 1) <=> ($b['risk'] === 'red' ? 0 : 1));
        $this->view('alerts/index', ['title' => 'Alertes', 'alerts' => $alerts, 'codes' => $codes, 'risky' => $risky]);
    }

    public function acknowledge(string $id): void
    {
        try {
            AlertService::acknowledge((int)$id);
        } catch (\DomainException $e) {
            $this->fail($e->getMessage(), '/alertes');
        }
        $this->ok('Alerte prise en compte.', '/alertes');
    }

    public function count(): void
    {
        $this->json(['n' => AlertService::count((array)Auth::user())]);
    }
}
