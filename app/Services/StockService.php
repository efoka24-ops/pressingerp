<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

final class StockService
{
    public const TYPES = ['entree' => 'Entrée', 'sortie' => 'Sortie / consommation', 'inventaire' => 'Inventaire (stock réel)'];

    public function move(int $itemId, string $type, float $qty, string $note): void
    {
        if (!array_key_exists($type, self::TYPES)) {
            throw new \DomainException('Type de mouvement invalide.');
        }
        if ($qty < 0 || ($qty == 0 && $type !== 'inventaire')) {
            throw new \DomainException('Quantité invalide.');
        }
        Database::transaction(function () use ($itemId, $type, $qty, $note): void {
            $item = Database::one('SELECT * FROM stock_items WHERE id = ? FOR UPDATE', [$itemId]) ?? throw new \DomainException('Article introuvable.');
            $delta = match ($type) {
                'entree'     => $qty,
                'sortie'     => -$qty,
                'inventaire' => $qty - (float)$item['quantity'],
            };
            if ((float)$item['quantity'] + $delta < 0) {
                throw new \DomainException('Stock insuffisant.');
            }
            Database::run('UPDATE stock_items SET quantity = quantity + ? WHERE id = ?', [$delta, $itemId]);
            Database::insert('stock_movements', [
                'stock_item_id' => $itemId,
                'type'          => $type,
                'qty'           => $delta,
                'note'          => $note ?: null,
                'user_id'       => Auth::id() ?: null,
                'created_at'    => now(),
            ]);
        });
    }

    public function order(int $itemId, float $qty): int
    {
        if ($qty <= 0) {
            throw new \DomainException('Quantité invalide.');
        }
        return Database::insert('purchase_orders', ['stock_item_id' => $itemId, 'qty' => $qty, 'status' => 'commandee', 'user_id' => Auth::id() ?: null, 'created_at' => now()]);
    }

    public function receive(int $poId): void
    {
        Database::transaction(function () use ($poId): void {
            $po = Database::one("SELECT * FROM purchase_orders WHERE id = ? AND status = 'commandee' FOR UPDATE", [$poId]) ?? throw new \DomainException('Bon de commande introuvable ou déjà reçu.');
            $this->move((int)$po['stock_item_id'], 'entree', (float)$po['qty'], 'Réception bon n° ' . $poId);
            Database::update('purchase_orders', ['status' => 'recue', 'received_at' => now()], 'id = :id', ['id' => $poId]);
        });
    }
}
