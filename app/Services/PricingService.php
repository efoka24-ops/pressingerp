<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Domain\ServiceLevel;

final class PricingService
{
    /** Ordre de priorité des grilles (décision D4) : la première grille applicable qui a un prix pour l'article l'emporte. */
    public const PRIORITY = ['business' => 0, 'agency' => 1, 'vip' => 2, 'promo' => 3, 'standard' => 4];

    public const KINDS = [
        'business' => 'Contrat entreprise (client précis)',
        'agency'   => 'Tarif agence',
        'vip'      => 'Tarif VIP (clients VIP)',
        'promo'    => 'Promotion (période)',
        'standard' => 'Tarif standard',
    ];

    /** @var array<string,?array> cache par requête */
    private static array $cache = [];

    public static function resetCache(): void
    {
        self::$cache = [];
    }

    /**
     * Devis d'une commande.
     * $lines : [['article_id'=>…, 'qty'=>…, 'brand'=>…, 'color'=>…, 'material'=>…, 'damages'=>…], …]
     * Un article "à la pièce" en quantité N donne N pièces (une étiquette QR chacune) ;
     * un article "au m²" donne une seule pièce dont le prix dépend de la surface.
     */
    public function quote(int $clientId, ServiceLevel $level, array $lines, bool $delivery = false, ?int $agencyId = null, ?string $asOf = null): array
    {
        $agencyId ??= Auth::agencyId();
        $ids = array_values(array_unique(array_map(fn($l) => (int)($l['article_id'] ?? 0), $lines)));
        $articles = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Database::all("SELECT * FROM articles WHERE id IN ($in)", $ids) as $a) {
                $articles[(int)$a['id']] = $a;
            }
        }

        $threshold = (int)SettingsService::get('photo.value_threshold');
        $items = [];
        $subtotal = 0;
        $photoLines = [];
        foreach ($lines as $index => $l) {
            $a = $articles[(int)($l['article_id'] ?? 0)] ?? throw new \DomainException('Article inconnu.');
            $p = $this->price((int)$a['id'], $clientId, $agencyId, null, $asOf) ?? throw new PricingMissing((int)$a['id'], (string)$a['name']);
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
                'tariff'     => $p['kind'] === 'standard' ? null : $p['list'],
                'treatment_id' => (int)($l['treatment_id'] ?? 0) ?: null,
            ];
            if ($a['unit'] === 'm2') {
                $qty = max(0.1, round($qty, 2));
                $price = (int)round($p['price'] * $qty);
                $items[] = $base + ['qty' => $qty, 'price' => $price];
                $subtotal += $price;
            } else {
                $n = max(1, min(100, (int)$qty));
                for ($i = 0; $i < $n; $i++) {
                    $items[] = $base + ['qty' => 1, 'price' => $p['price']];
                    $subtotal += $p['price'];
                }
            }
        }
        foreach ($items as &$it) {
            $it['photo_reason'] = self::photoReason($it, $threshold);
            if ($it['photo_reason'] !== null) {
                $photoLines[$it['line']] = $it['photo_reason'];
            }
        }
        unset($it);

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
            'photo_lines'    => $photoLines,
            'tariffs'        => array_values(array_unique(array_filter(array_column($items, 'tariff')))),
        ];
    }

    /** Motif d'obligation de photo pour une pièce, ou null : fragile, déjà endommagée, ou de valeur. */
    public static function photoReason(array $item, int $threshold): ?string
    {
        if (!empty($item['fragile'])) {
            return 'article fragile';
        }
        if (($item['damages'] ?? '') !== '') {
            return 'déjà endommagé';
        }
        if ($threshold > 0 && (int)$item['price'] >= $threshold) {
            return 'pièce de valeur (≥ ' . number_format($threshold, 0, ',', ' ') . ' FCFA)';
        }
        return null;
    }

    /**
     * Prix unitaire applicable à un article pour ce client et cette agence.
     * @return ?array{price:int,list:string,kind:string}
     */
    public function price(int $articleId, int $clientId, int $agencyId, ?string $date = null, ?string $asOf = null, bool $assumeVip = false): ?array
    {
        // $asOf (date et heure) : prix tels qu'ils étaient à cet instant (commande saisie hors-ligne)
        $date ??= $asOf !== null ? substr($asOf, 0, 10) : date('Y-m-d');
        $key = "$articleId|$clientId|$agencyId|$date|$asOf|" . (int)$assumeVip;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $isVip = $assumeVip || ($clientId > 0 && (bool)Database::value('SELECT is_vip FROM clients WHERE id = ?', [$clientId]));
        $lists = Database::all(
            'SELECT * FROM price_lists WHERE active = 1 AND (valid_from IS NULL OR valid_from <= :d) AND (valid_to IS NULL OR valid_to >= :d)',
            ['d' => $date]
        );
        $lists = array_filter($lists, fn($l) => match ($l['kind']) {
            'business' => $clientId > 0 && (int)$l['client_id'] === $clientId,
            'agency'   => (int)$l['agency_id'] === $agencyId,
            'vip'      => $isVip,
            default    => true,
        });
        usort($lists, fn($a, $b) => (self::PRIORITY[$a['kind']] ?? 9) <=> (self::PRIORITY[$b['kind']] ?? 9) ?: (int)$b['id'] <=> (int)$a['id']);

        foreach ($lists as $l) {
            $row = $asOf === null
                ? Database::one('SELECT price FROM price_items WHERE list_id = ? AND article_id = ? ORDER BY id DESC LIMIT 1', [$l['id'], $articleId])
                : Database::one('SELECT price FROM price_items WHERE list_id = ? AND article_id = ? AND created_at <= ? ORDER BY id DESC LIMIT 1', [$l['id'], $articleId, $asOf]);
            if ($row && $row['price'] !== null) {
                return self::$cache[$key] = ['price' => (int)$row['price'], 'list' => (string)$l['name'], 'kind' => (string)$l['kind']];
            }
        }
        // Repli sur l'ancien prix du catalogue tant qu'une grille standard n'a pas été saisie pour cet article
        $legacy = Database::one('SELECT price FROM articles WHERE id = ? AND price > 0', [$articleId]);
        return self::$cache[$key] = $legacy ? ['price' => (int)$legacy['price'], 'list' => 'Catalogue', 'kind' => 'standard'] : null;
    }

    /**
     * Enregistre une nouvelle version du prix d'un article dans une grille (null = retire l'article de la grille).
     * @return bool false si le prix est inchangé
     */
    public function setPrice(array $list, array $article, ?int $new, string $reason): bool
    {
        if (trim($reason) === '') {
            throw new \DomainException('Motif obligatoire pour modifier un prix.');
        }
        if ($new !== null && ($new <= 0 || $new > 10_000_000)) {
            throw new \DomainException('Prix invalide : entier positif en FCFA.');
        }
        $prev = Database::one('SELECT price FROM price_items WHERE list_id = ? AND article_id = ? ORDER BY id DESC LIMIT 1', [$list['id'], $article['id']]);
        $old = $prev ? ($prev['price'] === null ? null : (int)$prev['price']) : null;
        if ($prev && $old === $new) {
            return false;
        }
        Database::transaction(function () use ($list, $article, $new, $old, $reason): void {
            Database::insert('price_items', ['list_id' => $list['id'], 'article_id' => $article['id'], 'price' => $new, 'reason' => mb_substr($reason, 0, 255), 'created_by' => Auth::id() ?: null, 'created_at' => now()]);
            Audit::log('pricing.set', 'articles', (int)$article['id'], ['list' => $list['name'], 'article' => $article['name']], $old, $new, $reason);
        });
        self::resetCache();
        return true;
    }

    /** TVA incluse dans un montant TTC. */
    public static function vatIncluded(int $total): int
    {
        $rate = (float)str_replace(',', '.', (string)SettingsService::get('tax.vat_rate'));
        return $rate > 0 ? (int)round($total - $total / (1 + $rate / 100)) : 0;
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
