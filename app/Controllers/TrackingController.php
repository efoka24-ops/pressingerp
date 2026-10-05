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
        $number = strtoupper($this->str('number'));
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
            'balance'  => (int)$o['on_account'] ? 0 : max(0, (int)$o['total'] - (int)$o['paid']),
            'fee'      => (int)Config::get('delivery_fee', 0),
            'checkout' => (string)Config::get('mobile_money.checkout_url', ''),
        ], 'public');
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
