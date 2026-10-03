<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Domain\Step;
use App\Services\Audit;
use App\Services\Numbering;
use App\Services\QualityService;
use App\Services\WorkflowService;

final class QualityController extends Controller
{
    private const COMPLAINT_STATUSES = ['ouverte' => 'Ouverte', 'enquete' => 'Enquête', 'geste' => 'Geste proposé', 'cloturee' => 'Clôturée'];

    public function index(): void
    {
        $queue = Database::all(
            "SELECT g.*, o.number, o.promised_at, o.service_level, c.name client, c.is_vip
             FROM garments g JOIN orders o ON o.id = g.order_id JOIN clients c ON c.id = o.client_id
             WHERE g.step = 'controle' AND o.status = 'en_atelier'
             ORDER BY o.service_level = 'express' DESC, o.promised_at LIMIT 100"
        );
        $stats = Database::one("SELECT COUNT(*) n, SUM(result = 'reprise') k FROM quality_checks WHERE created_at > NOW() - INTERVAL 7 DAY");
        $reasons = Database::all("SELECT reason, COUNT(*) n FROM quality_checks WHERE result = 'reprise' AND created_at > NOW() - INTERVAL 7 DAY GROUP BY reason ORDER BY n DESC");
        // Poste qui génère le plus de reprises (étape juste avant le contrôle la plus fréquente parmi les pièces reprises)
        $hotspot = Database::one(
            "SELECT back_to, COUNT(*) n FROM quality_checks WHERE result = 'reprise' AND created_at > NOW() - INTERVAL 7 DAY GROUP BY back_to ORDER BY n DESC LIMIT 1"
        );
        $complaints = Database::all(
            "SELECT cp.*, c.name client, o.number order_number, u.name assignee
             FROM complaints cp JOIN clients c ON c.id = cp.client_id LEFT JOIN orders o ON o.id = cp.order_id LEFT JOIN users u ON u.id = cp.assigned_to
             ORDER BY cp.status = 'cloturee', cp.created_at DESC LIMIT 30"
        );
        $this->view('quality/index', [
            'title'      => 'Qualité',
            'queue'      => $queue,
            'checked'    => (int)$stats['n'],
            'rate'       => pct((float)$stats['k'], (float)$stats['n'], 1),
            'target'     => (float)(Database::value("SELECT target FROM objectives WHERE month = ? AND metric = 'reprise_max'", [date('Y-m')]) ?? 3),
            'reasons'    => $reasons,
            'hotspot'    => $hotspot,
            'complaints' => $complaints,
            'compensation' => (int)Database::value('SELECT COALESCE(SUM(compensation), 0) FROM complaints WHERE created_at > NOW() - INTERVAL 30 DAY'),
            'statuses'   => self::COMPLAINT_STATUSES,
            'users'      => Database::all("SELECT id, name FROM users WHERE active = 1 AND role IN ('direction', 'manager', 'qualite', 'comptoir') ORDER BY name"),
        ]);
    }

    public function check(string $id): void
    {
        $g = Database::one(
            'SELECT g.*, o.number, o.service_level, o.promised_at, o.notes order_notes, c.name client, c.preferences
             FROM garments g JOIN orders o ON o.id = g.order_id JOIN clients c ON c.id = o.client_id WHERE g.id = ?',
            [(int)$id]
        ) ?? throw new HttpException(404, 'Pièce introuvable');
        if ($g['step'] !== Step::Controle->value) {
            flash('warn', 'Cette pièce n\'est plus au contrôle (étape : ' . Step::from($g['step'])->label() . ').');
            redirect('/qualite');
        }
        $this->view('quality/check', [
            'title'    => 'Contrôle ' . $g['code'],
            'g'        => $g,
            'events'   => WorkflowService::events((int)$g['id']),
            'criteria' => QualityService::CRITERIA,
            'reasons'  => QualityService::REASONS,
            'targets'  => Step::reworkTargets(),
            'queueLeft' => (int)Database::value("SELECT COUNT(*) FROM garments WHERE step = 'controle'"),
        ]);
    }

    public function submit(string $id): void
    {
        $failed = array_keys(array_filter((array)($_POST['ko'] ?? [])));
        try {
            $result = (new QualityService())->check((int)$id, $failed, $this->str('reason') ?: null, Step::tryFrom($this->str('back_to')));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        // Enchaîne sur la pièce suivante de la file
        $next = Database::value(
            "SELECT g.id FROM garments g JOIN orders o ON o.id = g.order_id WHERE g.step = 'controle' ORDER BY o.service_level = 'express' DESC, o.promised_at LIMIT 1"
        );
        $msg = $result === 'conforme' ? 'Pièce conforme → emballage.' : 'Pièce renvoyée en reprise.';
        $this->ok($msg, $next ? '/qualite/controle/' . $next : '/qualite');
    }

    public function storeComplaint(): void
    {
        $req = $this->required(['subject' => 'Objet']);
        $order = null;
        if ($num = strtoupper($this->str('order_number'))) {
            $order = Database::one('SELECT id, client_id FROM orders WHERE number = ?', [$num]) ?? $this->fail("Commande $num introuvable.");
        }
        $clientId = $order['client_id'] ?? $this->int('client_id');
        if (!$clientId) {
            $this->fail('Indiquez la commande concernée.');
        }
        $id = Database::insert('complaints', [
            'number'      => Numbering::next('complaint', 'RC-%2$04d'),
            'client_id'   => $clientId,
            'order_id'    => $order['id'] ?? null,
            'subject'     => mb_substr($req['subject'], 0, 200),
            'status'      => 'ouverte',
            'assigned_to' => $this->int('assigned_to') ?: Auth::id(),
            'created_at'  => now(),
        ]);
        Audit::log('complaint.create', 'complaints', $id);
        $this->ok('Réclamation enregistrée.', '/qualite#reclamations');
    }

    public function updateComplaint(string $id): void
    {
        $status = array_key_exists($this->str('status'), self::COMPLAINT_STATUSES) ? $this->str('status') : 'ouverte';
        $data = [
            'status'       => $status,
            'compensation' => max(0, $this->int('compensation')),
            'resolution'   => $this->str('resolution') ?: null,
            'closed_at'    => $status === 'cloturee' ? now() : null,
        ];
        if ($status === 'cloturee' && !$data['resolution']) {
            $this->fail('Décrivez la résolution avant de clôturer.');
        }
        Database::update('complaints', $data, 'id = :id', ['id' => (int)$id]);
        Audit::log('complaint.update', 'complaints', (int)$id, ['status' => $status]);
        $this->ok('Réclamation mise à jour.', '/qualite#reclamations');
    }
}
