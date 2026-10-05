<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Services\OfflineService;
use App\Services\Uploads;

/** Réception hors-ligne : page de saisie, autorisation des postes, données locales et synchronisation. */
final class OfflineController extends Controller
{
    /** Page autonome (sans menu ni nom d'utilisateur) : elle est mise en cache par le navigateur pour fonctionner sans réseau. */
    public function page(): void
    {
        header_remove('Pragma');
        header('Cache-Control: private, max-age=86400');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
        $this->view('offline/reception', ['title' => 'Réception hors-ligne'], null);
    }

    /** Un responsable autorise le poste courant : le jeton est mémorisé par le navigateur (affiché une seule fois). */
    public function register(): void
    {
        try {
            $r = (new OfflineService())->register(Auth::agencyId(), $this->str('label'));
        } catch (\DomainException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $this->json(['ok' => true] + $r);
    }

    /** Données locales du poste, plus une plage de numéros si nécessaire. */
    public function data(): void
    {
        $svc = new OfflineService();
        try {
            $ws = $svc->authenticate((string)($_SERVER['HTTP_X_STATION'] ?? ''));
            $block = $svc->allocateBlockIfNeeded($ws);
            $this->json(['ok' => true, 'block' => $block, 'unused' => $svc->unused($ws)] + $svc->snapshot($ws));
        } catch (\DomainException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 403);
        }
    }

    /** Jeton CSRF frais : les pages en cache portent un jeton périmé. */
    public function csrf(): void
    {
        $this->json(['ok' => true, 'token' => Csrf::token(), 'user' => Auth::id()]);
    }

    /** Synchronise une commande (multipart : « payload » en JSON + photos[ligne]). */
    public function sync(): void
    {
        $svc = new OfflineService();
        try {
            $ws = $svc->authenticate((string)($_SERVER['HTTP_X_STATION'] ?? ''));
            $payload = json_decode((string)($_POST['payload'] ?? ''), true);
            if (!is_array($payload)) {
                throw new \DomainException('Commande illisible.');
            }
            $this->json(['ok' => true] + $svc->syncOrder($ws, $payload, Uploads::normalize($_FILES['photos'] ?? [])));
        } catch (\DomainException $e) {
            $this->json(['ok' => false, 'status' => 'rejected', 'error' => $e->getMessage()], 422);
        }
    }

    // --- Administration des postes ------------------------------------------------------------

    public function stations(): void
    {
        $this->view('admin/stations', [
            'title'    => 'Postes hors-ligne',
            'stations' => OfflineService::stations(),
            'rejected' => Database::all(
                "SELECT s.*, w.label FROM offline_syncs s JOIN workstations w ON w.id = s.workstation_id WHERE s.status = 'rejected' ORDER BY s.received_at DESC LIMIT 50"
            ),
        ]);
    }

    public function deactivate(string $id): void
    {
        try {
            (new OfflineService())->deactivate((int)$id, $this->str('reason'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Poste désactivé : ses numéros non utilisés restent tracés et ne seront pas réattribués.', '/admin/postes');
    }
}
