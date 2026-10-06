<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Recherche globale : téléphone, nom, code client, n° de commande, QR / n° de pièce, facture, avoir, devis.
 * Chaque famille de résultats n'est cherchée que si le profil a le droit d'y accéder, et les commandes et pièces
 * restent limitées à l'agence des profils limités à leur agence.
 */
final class SearchService
{
    /** Forme exacte d'un numéro de document commercial : FA-GAR-2026-00001, AV-…, DV-… (ou l'ancien FA-2026-00001). */
    private const DOC = '/^(FA|AV|DV)-(?:[A-Z0-9]{2,10}-)?\d{4}-\d{5}$/';

    /**
     * @return array{redirect:?string,clients:list<array>,orders:list<array>,garments:list<array>,invoices:list<array>,quotes:list<array>}
     */
    public static function run(string $q): array
    {
        $q = trim($q);
        $Q = strtoupper(preg_replace('/\s+/', '', $q) ?? '');
        $out = ['redirect' => null, 'clients' => [], 'orders' => [], 'garments' => [], 'invoices' => [], 'quotes' => []];
        $canClients = Auth::can('clients');
        $canOrders = Auth::can('orders');
        $canTrace = Auth::can('trace') || Auth::can('production');
        $canCommercial = Auth::can('commercial');
        $scopeO = Auth::scopeSql('o.agency_id');

        // Numéro exact : on va directement au document
        if (preg_match('/^PR-\d{4}-\d{6}-\d{2,}$/', $Q) && $canTrace && Database::value('SELECT g.id FROM garments g JOIN orders o ON o.id = g.order_id WHERE g.code = ?' . $scopeO, [$Q])) {
            $out['redirect'] = Auth::can('production') ? '/scan?code=' . urlencode($Q) : '/tracabilite?q=' . urlencode($Q);
            return $out;
        }
        if (preg_match('/^PR-\d{4}-\d{6}$/', $Q) && $canOrders && ($id = Database::value('SELECT o.id FROM orders o WHERE o.number = ?' . $scopeO, [$Q]))) {
            $out['redirect'] = '/commandes/' . $id;
            return $out;
        }
        if (preg_match(self::DOC, $Q) && $canCommercial) {
            if (str_starts_with($Q, 'DV-')) {
                if ($id = Database::value('SELECT id FROM quotes WHERE number = ?', [$Q])) {
                    $out['redirect'] = '/commercial/devis/' . $id;
                    return $out;
                }
            } elseif ($id = Database::value('SELECT id FROM invoices WHERE number = ?', [$Q])) {
                $out['redirect'] = '/commercial/factures/' . $id;
                return $out;
            }
        }
        if (mb_strlen($q) < 2) {
            return $out;
        }

        $digits = preg_replace('/\D/', '', $q) ?? '';
        if ($canClients) {
            $out['clients'] = Database::all(
                'SELECT id, code, name, phone, is_vip, type FROM clients WHERE name LIKE :q OR code LIKE :q' . (strlen($digits) >= 4 ? ' OR phone LIKE :d' : '') . ' ORDER BY name LIMIT 20',
                ['q' => "%$q%"] + (strlen($digits) >= 4 ? ['d' => "%$digits%"] : [])
            );
        }
        if ($canOrders) {
            $out['orders'] = Database::all(
                'SELECT o.id, o.number, o.status, o.total, o.created_at, c.name client FROM orders o JOIN clients c ON c.id = o.client_id
                 WHERE (o.number LIKE ? OR (c.phone LIKE ? AND ? >= 6))' . $scopeO . ' ORDER BY o.id DESC LIMIT 20',
                ["%$Q%", '%' . ($digits ?: '@@') . '%', strlen($digits)]
            );
        }
        if ($canTrace && strlen($Q) >= 6 && str_starts_with($Q, 'PR-')) {
            $out['garments'] = Database::all(
                'SELECT g.id, g.code, g.label, g.step, g.order_id, o.number FROM garments g JOIN orders o ON o.id = g.order_id WHERE g.code LIKE ?' . $scopeO . ' ORDER BY g.id DESC LIMIT 20',
                ["%$Q%"]
            );
        }
        if ($canCommercial && strlen($Q) >= 4) {
            $out['invoices'] = Database::all(
                "SELECT i.id, i.number, i.kind, i.total, i.paid, i.status, i.created_at, c.name client FROM invoices i JOIN clients c ON c.id = i.client_id
                 WHERE i.number LIKE ? OR c.name LIKE ? ORDER BY i.id DESC LIMIT 20",
                ["%$Q%", "%$q%"]
            );
            $out['quotes'] = Database::all(
                'SELECT q.id, q.number, q.total, q.status, q.created_at, c.name client FROM quotes q JOIN clients c ON c.id = q.client_id WHERE q.number LIKE ? OR c.name LIKE ? ORDER BY q.id DESC LIMIT 10',
                ["%$Q%", "%$q%"]
            );
        }
        return $out;
    }
}
