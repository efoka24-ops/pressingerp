<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Domain\ServiceLevel;

final class PricingService
{
    /**
     * Devis d'une commande.
     * $lines : [['article_id'=>…, 'qty'=>…, 'brand'=>…, 'color'=>…, 'material'=>…, 'damages'=>…], …]
     * Un article "à la pièce" en quantité N donne N pièces (une étiquette QR chacune) ;
     * un article "au m²" donne une seule pièce dont le prix dépend de la surface.
     */
    public function quote(int $clientId, ServiceLevel $level, array $lines, bool $delivery = false): array
    {
        $ids = array_values(array_unique(array_map(fn($l) => (int)($l['article_id'] ?? 0), $lines)));
        $articles = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Database::all("SELECT * FROM articles WHERE id IN ($in)", $ids) as $a) {
                $articles[(int)$a['id']] = $a;
            }
        }

        $items = [];
        $subtotal = 0;
        foreach ($lines as $index => $l) {
            $a = $articles[(int)($l['article_id'] ?? 0)] ?? throw new \DomainException('Article inconnu.');
            $qty = (float)str_replace(',', '.', (string)($l['qty'] ?? 1));
            $base = [
                'line'       => $index,
                'article_id' => (int)$a['id'],
                'label'      => $a['name'],
                'fragile'    => (bool)$a['fragile'],
                'brand'      => trim((string)($l['brand'] ?? '')),
                'color'      => trim((string)($l['color'] ?? '')),
                'material'   => trim((string)($l['material'] ?? '')),
                'damages'    => trim((string)($l['damages'] ?? '')),
            ];
            if ($a['unit'] === 'm2') {
                $qty = max(0.1, round($qty, 2));
                $price = (int)round($a['price'] * $qty);
                $items[] = $base + ['qty' => $qty, 'price' => $price];
                $subtotal += $price;
            } else {
                $n = max(1, min(100, (int)$qty));
                for ($i = 0; $i < $n; $i++) {
                    $items[] = $base + ['qty' => 1, 'price' => (int)$a['price']];
                    $subtotal += (int)$a['price'];
                }
            }
        }

        $surcharge = (int)round($subtotal * $level->surchargePct() / 100);
        [$discount, $label] = $this->discount($clientId, $subtotal + $surcharge);
        $fee = $delivery ? (int)Config::get('delivery_fee', 0) : 0;

        return [
            'lines'          => $items,
            'subtotal'       => $subtotal,
            'surcharge'      => $surcharge,
            'discount'       => $discount,
            'discount_label' => $label,
            'delivery_fee'   => $fee,
            'total'          => max(0, $subtotal + $surcharge - $discount + $fee),
        ];
    }

    /** Remise contrat (clients pros) ou remise fidélité sur la Nième commande. */
    private function discount(int $clientId, int $base): array
    {
        if ($clientId <= 0 || $base <= 0) {
            return [0, null];
        }
        $contract = Database::one(
            'SELECT discount_pct FROM contracts WHERE client_id = ? AND active = 1 AND CURDATE() BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1',
            [$clientId]
        );
        if ($contract && (float)$contract['discount_pct'] > 0) {
            $pct = (float)$contract['discount_pct'];
            return [(int)round($base * $pct / 100), 'Contrat −' . rtrim(rtrim(number_format($pct, 1, ',', ''), '0'), ',') . ' %'];
        }
        $nth = (int)Config::get('loyalty.every_nth_order', 0);
        if ($nth > 0) {
            $count = (int)Database::value("SELECT COUNT(*) FROM orders WHERE client_id = ? AND status <> 'annule'", [$clientId]) + 1;
            if ($count % $nth === 0) {
                $pct = (int)Config::get('loyalty.nth_discount_pct', 10);
                return [(int)round($base * $pct / 100), "Fidélité — {$count}e commande (−{$pct} %)"];
            }
        }
        return [0, null];
    }
}
