<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Domain\Step;
use App\Services\Audit;
use App\Services\ClientService;
use App\Services\Notifier;

/** Site public de suivi : lien unique envoyé par SMS ou QR imprimé sur le ticket. */
final class TrackingController extends Controller
{
    public function lookup(): void
    {
        $this->view('tracking/lookup', ['title' => 'Suivre ma commande'], 'public');
    }

    public function find(): void
    {
        // Le numéro du ticket ou le code d'une pièce (PR-2026-000124-02) mène à la même commande
        $number = preg_replace('/^(PR-\d{4}-\d{6})-\d{2,}$/', '$1', strtoupper(preg_replace('/\s+/', '', $this->str('number'))));
        $phone = ClientService::normalizePhone($this->str('phone'));
        $token = Database::value('SELECT o.tracking_token FROM orders o JOIN clients c ON c.id = o.client_id WHERE o.number = ? AND c.phone = ?', [$number, $phone]);
        if (!$token) {
            usleep(300_000);
            $this->fail('Aucune commande ne correspond à ce numéro et ce téléphone.', '/suivi');
        }
        redirect('/suivi/' . $token);
    }

    public function show(string $token): void
    {
        $o = $this->find_($token);
        $garments = Database::all('SELECT label, step, status FROM garments WHERE order_id = ? ORDER BY seq', [$o['id']]);
        $min = min(array_map(fn($g) => Step::from($g['step'])->index(), $garments) ?: [0]);
        $stage = match (true) {
            in_array($o['status'], ['retire', 'livre'], true) => 4,
            $o['status'] === 'pret' => 3,
            $min >= Step::Controle->index() => 2,
            $min >= Step::Tri->index() => 1,
            default => 0,
        };
        $this->view('tracking/show', [
            'title'    => 'Commande ' . $o['number'],
            'o'        => $o,
            'garments' => $garments,
            'stage'    => $stage,
            'history'  => $this->history($o),
            'balance'  => (int)$o['on_account'] ? 0 : max(0, (int)$o['total'] - (int)$o['paid']),
            'fee'      => (int)Config::get('delivery_fee', 0),
            'checkout' => (string)Config::get('mobile_money.checkout_url', ''),
        ], 'public');
    }

    /**
     * Historique des actions sur la commande, en termes que le client comprend (aucun nom d'agent ni note interne).
     * @return list<array{0:string,1:string}> [horodatage, texte], du plus ancien au plus récent
     */
    private function history(array $o): array
    {
        $id = (int)$o['id'];
        $n = (int)Database::value('SELECT COUNT(*) FROM garments WHERE order_id = ?', [$id]);
        $ev = [[$o['created_at'], 'Commande enregistrée (' . $n . ' pièce' . ($n > 1 ? 's' : '') . ')']];
        foreach (Database::all(
            "SELECT e.step, MIN(e.created_at) ts FROM garment_events e JOIN garments g ON g.id = e.garment_id WHERE g.order_id = ? AND e.step NOT IN ('reception', 'retire', 'pret') GROUP BY e.step",
            [$id]
        ) as $r) {
            $step = Step::tryFrom($r['step']);
            if ($step) {
                $ev[] = [$r['ts'], 'Étape « ' . $step->label() . ' »'];
            }
        }
        foreach (Database::all("SELECT amount, method, created_at FROM payments WHERE order_id = ? AND kind = 'payment' AND amount > 0 ORDER BY id", [$id]) as $p) {
            $ev[] = [$p['created_at'], 'Paiement reçu : ' . money($p['amount'], true)];
        }
        if ($o['ready_at']) {
            $ev[] = [$o['ready_at'], 'Commande prête'];
        }
        $labels = ['en_route' => 'Le livreur est en route', 'livre' => 'Livraison effectuée', 'non_livre' => 'Livraison non aboutie, une nouvelle tentative sera proposée', 'a_livrer' => 'Livraison programmée'];
        foreach (Database::all("SELECT e.status, e.created_at FROM delivery_events e JOIN deliveries d ON d.id = e.delivery_id WHERE d.order_id = ? ORDER BY e.id", [$id]) as $d) {
            if (isset($labels[$d['status']])) {
                $ev[] = [$d['created_at'], $labels[$d['status']]];
            }
        }
        if ($o['picked_up_at']) {
            $ev[] = [$o['picked_up_at'], $o['status'] === 'livre' ? 'Commande livrée' : 'Commande retirée'];
        }
        usort($ev, fn($a, $b) => strcmp($a[0], $b[0]));
        return $ev;
    }

    public function delivery(string $token): void
    {
        $o = $this->find_($token);
        $address = mb_substr($this->str('address'), 0, 255);
        if ($address === '') {
            $this->fail('Indiquez votre adresse de livraison.');
        }
        if (!in_array($o['status'], ['en_atelier', 'pret'], true) || $o['delivery_address']) {
            $this->fail('La livraison ne peut plus être demandée pour cette commande.');
        }
        $fee = (int)Config::get('delivery_fee', 0);
        Database::update('orders', ['delivery_address' => $address, 'delivery_fee' => $fee, 'total' => (int)$o['total'] + $fee], 'id = :id', ['id' => $o['id']]);
        \App\Services\DeliveryService::ensureForOrder((int)$o['id']);
        Notifier::queue((int)$o['client_id'], "Pressing : livraison de {$o['number']} confirmée à l'adresse : $address. Frais : " . money($fee, true) . '.');
        Audit::log('tracking.delivery', 'orders', (int)$o['id']);
        $this->ok('Livraison demandée. L\'agence vous appellera pour convenir du créneau.', '/suivi/' . $token);
    }

    private function find_(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new HttpException(404, 'Lien de suivi invalide.');
        }
        return Database::one(
            'SELECT o.*, c.name client, a.name agency, a.phone agency_phone FROM orders o JOIN clients c ON c.id = o.client_id JOIN agencies a ON a.id = o.agency_id WHERE o.tracking_token = ?',
            [$token]
        ) ?? throw new HttpException(404, 'Commande introuvable.');
    }
}
