<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\AgencyRequestService;

/** Demande d'ouverture d'un pressing (page publique) et son examen par le super administrateur. */
final class AgencyRequestController extends Controller
{
    private const STATUS = ['en_attente' => 'En attente', 'validee' => 'Validée', 'refusee' => 'Refusée'];

    // --- Public ---------------------------------------------------------------------------------

    public function form(): void
    {
        $this->view('agency_request/form', ['title' => 'Ouvrir mon pressing'], 'public');
    }

    public function submit(): void
    {
        // Champ piège : un robot le remplit, une personne ne le voit pas
        if ($this->str('website') !== '') {
            redirect('/ouvrir-un-pressing/merci');
        }
        try {
            (new AgencyRequestService())->submit($_POST, $_SERVER['REMOTE_ADDR'] ?? null);
        } catch (\DomainException $e) {
            $_SESSION['_old'] = $_POST;
            $this->fail($e->getMessage(), '/ouvrir-un-pressing');
        }
        unset($_SESSION['_old']);
        redirect('/ouvrir-un-pressing/merci');
    }

    public function thanks(): void
    {
        $this->view('agency_request/thanks', ['title' => 'Demande reçue'], 'public');
    }

    // --- Super administrateur -------------------------------------------------------------------

    public function index(): void
    {
        $status = array_key_exists($this->str('statut'), self::STATUS) ? $this->str('statut') : 'en_attente';
        $this->view('admin/agency_requests', [
            'title'    => 'Demandes d\'ouverture',
            'rows'     => Database::all('SELECT r.*, u.name reviewer FROM agency_requests r LEFT JOIN users u ON u.id = r.reviewed_by WHERE r.status = ? ORDER BY r.id DESC LIMIT 100', [$status]),
            'status'   => $status,
            'statuses' => self::STATUS,
            'counts'   => array_column(Database::all('SELECT status, COUNT(*) n FROM agency_requests GROUP BY status'), 'n', 'status'),
            'suggest'  => fn(string $city) => AgencyRequestService::freeCode($city),
        ]);
    }

    public function approve(string $id): void
    {
        try {
            $r = (new AgencyRequestService())->approve((int)$id, $this->str('code'), $this->str('name'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage(), '/admin/demandes');
        }
        $msg = match ($r['mail']) {
            'envoye' => "Pressing validé. Les identifiants ({$r['login']}) ont été envoyés par e-mail au responsable.",
            default  => "Pressing validé, mais l'e-mail n'a pas pu être envoyé ({$r['mail_error']}). À transmettre au responsable : nom d'utilisateur {$r['login']} · mot de passe provisoire {$r['password']} (affiché une seule fois).",
        };
        $this->ok($msg, '/admin/demandes');
    }

    public function reject(string $id): void
    {
        try {
            $r = (new AgencyRequestService())->reject((int)$id, $this->str('reason'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage(), '/admin/demandes');
        }
        $this->ok($r['mail'] === 'envoye' ? 'Demande refusée, le demandeur a été prévenu par e-mail.' : 'Demande refusée. L\'e-mail au demandeur n\'a pas pu être envoyé : prévenez-le.', '/admin/demandes');
    }
}
