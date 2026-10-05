<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Domain\PaymentMethod;
use App\Services\Audit;
use App\Services\CashService;

final class CashController extends Controller
{
    public function index(): void
    {
        $cash = new CashService();
        $session = $cash->current(Auth::id());
        $month = date('Y-m-01');
        // Un rôle limité à son agence ne voit que ses caisses ; sans périmètre, les caisses de toutes les agences
        $agencyFilter = Auth::scopedAgencyId() ? ' AND cs.agency_id = ' . Auth::scopedAgencyId() : (Auth::isManager() ? '' : ' AND cs.agency_id = ' . Auth::agencyId());
        $sessions = Database::all(
            "SELECT cs.*, a.name agency, u.name user,
                    (SELECT SUM(counted - expected) FROM cash_counts cc WHERE cc.cash_session_id = cs.id) diff,
                    (SELECT COALESCE(SUM(amount), 0) FROM payments p WHERE p.cash_session_id = cs.id) cashed
             FROM cash_sessions cs JOIN agencies a ON a.id = cs.agency_id JOIN users u ON u.id = cs.user_id
             WHERE (cs.opened_at >= CURDATE() OR cs.status = 'ouverte')" . $agencyFilter . '
             ORDER BY cs.status = \'ouverte\' DESC, cs.opened_at DESC'
        );
        $this->view('cash/index', [
            'title'    => 'Caisse & Finance',
            'session'  => $session,
            'expected' => $session ? $cash->expected($session) : [],
            'payments' => $session ? Database::all(
                'SELECT p.*, o.number, c.name client FROM payments p LEFT JOIN orders o ON o.id = p.order_id JOIN clients c ON c.id = p.client_id WHERE p.cash_session_id = ? ORDER BY p.created_at DESC LIMIT 40',
                [$session['id']]
            ) : [],
            'expenses' => $session ? Database::all('SELECT * FROM expenses WHERE cash_session_id = ? ORDER BY created_at DESC', [$session['id']]) : [],
            'sessions' => $sessions,
            'month'    => [
                'income'   => (int)Database::value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE created_at >= ?', [$month]),
                'expenses' => (int)Database::value('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE created_at >= ?', [$month]),
                'byMethod' => Database::all('SELECT method, SUM(amount) total FROM payments WHERE created_at >= ? GROUP BY method ORDER BY total DESC', [$month]),
            ],
            'methods'  => PaymentMethod::counter(),
        ]);
    }

    /** État de caisse imprimable d'une session (la sienne, ou celles de son agence pour un responsable). */
    public function statement(string $id): void
    {
        $session = Database::one('SELECT * FROM cash_sessions WHERE id = ?', [(int)$id]) ?? throw new \App\Core\HttpException(404, 'Caisse introuvable');
        $mine = (int)$session['user_id'] === Auth::id();
        if (!$mine && (!Auth::can('cash', 'validate') || !Auth::canSeeAgency((int)$session['agency_id']))) {
            throw new \App\Core\HttpException(403, 'Cet état de caisse ne vous concerne pas.');
        }
        $this->view('cash/statement', ['session' => $session, 'st' => (new CashService())->statement($session)], null);
    }

    public function open(): void
    {
        try {
            (new CashService())->open(Auth::id(), Auth::agencyId(), $this->int('opening_float'), $this->str('label'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Caisse ouverte.', Auth::can('counter') ? '/comptoir' : '/caisse');
    }

    public function closeForm(): void
    {
        $cash = new CashService();
        $session = $cash->current(Auth::id()) ?? $this->fail('Aucune caisse ouverte.', '/caisse');
        $this->view('cash/close', ['title' => 'Clôture de caisse', 'session' => $session, 'expected' => $cash->expected($session), 'methods' => PaymentMethod::counter()]);
    }

    public function close(): void
    {
        $cash = new CashService();
        $session = $cash->current(Auth::id()) ?? $this->fail('Aucune caisse ouverte.', '/caisse');
        try {
            $diff = $cash->close($session, array_map('intval', (array)($_POST['counted'] ?? [])), $this->str('justification'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Caisse clôturée' . ($diff !== 0 ? ' avec un écart de ' . money($diff, true) . ' (signalé à la direction).' : ' sans écart.'), '/caisse');
    }

    /** Un responsable clôture la caisse d'un autre agent (ex. livreur) de son agence. */
    private function otherSession(int $id): array
    {
        $s = Database::one("SELECT * FROM cash_sessions WHERE id = ? AND status = 'ouverte'", [$id]) ?? throw new HttpException(404, 'Caisse ouverte introuvable.');
        if (!Auth::canSeeAgency((int)$s['agency_id'])) {
            throw new HttpException(404, 'Caisse ouverte introuvable.');
        }
        return $s;
    }

    public function closeOtherForm(string $id): void
    {
        $s = $this->otherSession((int)$id);
        $this->view('cash/close', ['title' => 'Clôture de caisse', 'session' => $s, 'expected' => (new CashService())->expected($s), 'methods' => PaymentMethod::counter(), 'action' => '/caisse/' . $s['id'] . '/cloture']);
    }

    public function closeOther(string $id): void
    {
        $s = $this->otherSession((int)$id);
        try {
            $diff = (new CashService())->close($s, array_map('intval', (array)($_POST['counted'] ?? [])), $this->str('justification'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Caisse clôturée' . ($diff !== 0 ? ' avec un écart de ' . money($diff, true) . ' (signalé à la direction).' : ' sans écart.'), '/caisse');
    }

    public function expense(): void
    {
        $req = $this->required(['label' => 'Libellé']);
        $amount = $this->int('amount');
        if ($amount <= 0) {
            $this->fail('Montant invalide.');
        }
        $session = (new CashService())->current(Auth::id()) ?? $this->fail('Ouvrez votre caisse pour sortir une dépense en espèces.');
        $id = Database::insert('expenses', [
            'agency_id'       => Auth::agencyId(),
            'cash_session_id' => $session['id'],
            'label'           => mb_substr($req['label'], 0, 150),
            'amount'          => $amount,
            'user_id'         => Auth::id(),
            'created_at'      => now(),
        ]);
        Audit::log('expense.create', 'expenses', $id, ['amount' => $amount]);
        $this->ok('Dépense enregistrée.', '/caisse');
    }
}
