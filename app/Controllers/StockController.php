<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\Audit;
use App\Services\StockService;

final class StockController extends Controller
{
    public function index(): void
    {
        $items = Database::all(
            'SELECT s.*, (SELECT COUNT(*) FROM purchase_orders p WHERE p.stock_item_id = s.id AND p.status = \'commandee\') pending
             FROM stock_items s ORDER BY (s.quantity < s.min_qty) DESC, (s.quantity / NULLIF(s.daily_usage, 0)) ASC, s.name'
        );
        $this->view('stock/index', [
            'title'     => 'Stocks',
            'items'     => $items,
            'low'       => count(array_filter($items, fn($i) => (float)$i['quantity'] < (float)$i['min_qty'])),
            'movements' => Database::all('SELECT m.*, s.name item, s.unit, u.name user FROM stock_movements m JOIN stock_items s ON s.id = m.stock_item_id LEFT JOIN users u ON u.id = m.user_id ORDER BY m.created_at DESC LIMIT 20'),
            'orders'    => Database::all("SELECT p.*, s.name item, s.unit, s.supplier FROM purchase_orders p JOIN stock_items s ON s.id = p.stock_item_id WHERE p.status = 'commandee' ORDER BY p.created_at"),
            'types'     => StockService::TYPES,
        ]);
    }

    public function store(): void
    {
        $req = $this->required(['name' => 'Désignation', 'unit' => 'Unité']);
        $id = Database::insert('stock_items', [
            'name'        => mb_substr($req['name'], 0, 120),
            'unit'        => mb_substr($req['unit'], 0, 20),
            'quantity'    => max(0, (float)$this->str('quantity', '0')),
            'min_qty'     => max(0, (float)$this->str('min_qty', '0')),
            'daily_usage' => max(0, (float)$this->str('daily_usage', '0')),
            'supplier'    => $this->str('supplier') ?: null,
        ]);
        Audit::log('stock.create', 'stock_items', $id);
        $this->ok('Article de stock créé.', '/stocks');
    }

    public function move(): void
    {
        try {
            (new StockService())->move($this->int('item_id'), $this->str('type'), (float)str_replace(',', '.', $this->str('qty', '0')), $this->str('note'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Mouvement enregistré.', '/stocks');
    }

    public function order(): void
    {
        try {
            (new StockService())->order($this->int('item_id'), (float)str_replace(',', '.', $this->str('qty', '0')));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Bon de commande créé.', '/stocks');
    }

    public function receive(string $id): void
    {
        try {
            (new StockService())->receive((int)$id);
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Livraison fournisseur réceptionnée.', '/stocks');
    }
}
