<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\Audit;
use App\Services\PricingService;

/** Grilles tarifaires administrables : chaque prix modifié crée une version motivée et auditée. */
final class TariffController extends Controller
{
    public function index(): void
    {
        $allArticles = Database::all('SELECT id, name, unit, fragile, active FROM articles ORDER BY active DESC, sort, name');
        $articles = array_values(array_filter($allArticles, fn($a) => (int)$a['active'] === 1));
        $lists = Database::all(
            'SELECT l.*, a.name agency, c.name client FROM price_lists l LEFT JOIN agencies a ON a.id = l.agency_id LEFT JOIN clients c ON c.id = l.client_id ORDER BY l.active DESC, l.id'
        );
        foreach ($lists as &$l) {
            $l['prices'] = [];
            $rows = Database::all(
                'SELECT i.article_id, i.price FROM price_items i JOIN (SELECT article_id, MAX(id) id FROM price_items WHERE list_id = ? GROUP BY article_id) m ON m.id = i.id',
                [$l['id']]
            );
            foreach ($rows as $r) {
                $l['prices'][(int)$r['article_id']] = $r['price'] === null ? null : (int)$r['price'];
            }
        }
        unset($l);
        $this->view('tariffs/index', [
            'title'    => 'Tarifs',
            'lists'    => $lists,
            'articles' => $articles,
            'catalog'  => $allArticles,
            'agencies' => Database::all('SELECT id, name FROM agencies WHERE is_workshop = 0 ORDER BY name'),
            'kinds'    => PricingService::KINDS,
            'history'  => $this->int('histo') ? Database::all(
                'SELECT i.*, u.name user_name, ar.name article FROM price_items i LEFT JOIN users u ON u.id = i.created_by JOIN articles ar ON ar.id = i.article_id WHERE i.list_id = ? ORDER BY i.id DESC LIMIT 40',
                [$this->int('histo')]
            ) : [],
        ]);
    }

    public function createArticle(): void
    {
        $f = $this->required(['name' => 'Nom de la pièce', 'reason' => 'Motif']);
        try {
            (new PricingService())->createArticle($f['name'], $this->str('unit'), (bool)$this->int('fragile'), $this->int('price'), $f['reason']);
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok('Pièce ajoutée au catalogue, avec son prix standard. Elle est disponible à la réception.', '/tarifs');
    }

    public function updateArticle(string $id): void
    {
        try {
            $changed = (new PricingService())->updateArticle((int)$id, $this->str('name'), (bool)$this->int('fragile'), (bool)$this->int('active'), $this->str('reason'));
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        $this->ok($changed ? 'Pièce mise à jour.' : 'Aucun changement.', '/tarifs');
    }

    public function createList(): void
    {
        $kind = $this->str('kind');
        if (!isset(PricingService::KINDS[$kind]) || $kind === 'standard') {
            $this->fail('Type de grille invalide (la grille standard existe déjà).');
        }
        $name = $this->required(['name' => 'Nom de la grille'])['name'];
        $data = ['kind' => $kind, 'name' => mb_substr($name, 0, 120), 'agency_id' => null, 'client_id' => null, 'valid_from' => null, 'valid_to' => null, 'active' => 1, 'created_by' => \App\Core\Auth::id() ?: null, 'created_at' => now()];
        if ($kind === 'agency') {
            $data['agency_id'] = $this->int('agency_id');
            if (!Database::value('SELECT id FROM agencies WHERE id = ?', [$data['agency_id']])) {
                $this->fail('Choisissez une agence.');
            }
        }
        if ($kind === 'business') {
            $code = strtoupper($this->str('client_code'));
            $data['client_id'] = (int)Database::value('SELECT id FROM clients WHERE code = ?', [$code]);
            if (!$data['client_id']) {
                $this->fail('Client introuvable : saisissez son code (ex. CL-2026-000012).');
            }
        }
        if ($kind === 'promo') {
            $from = $this->str('valid_from');
            $to = $this->str('valid_to');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
                $this->fail('Une promotion exige une période valide (début ≤ fin).');
            }
            $data['valid_from'] = $from;
            $data['valid_to'] = $to;
        }
        $id = Database::insert('price_lists', $data);
        Audit::log('pricing.list.create', 'price_lists', $id, [], null, $data);
        PricingService::resetCache();
        $this->ok('Grille créée. Ajoutez maintenant ses prix.', '/tarifs');
    }

    public function toggleList(string $id): void
    {
        $l = Database::one('SELECT * FROM price_lists WHERE id = ?', [(int)$id]) ?? $this->fail('Grille introuvable.');
        if ($l['kind'] === 'standard') {
            $this->fail('La grille standard ne peut pas être désactivée.');
        }
        $reason = $this->required(['reason' => 'Motif'])['reason'];
        $new = $l['active'] ? 0 : 1;
        Database::update('price_lists', ['active' => $new], 'id = :id', ['id' => $l['id']]);
        Audit::log('pricing.list.toggle', 'price_lists', (int)$l['id'], ['name' => $l['name']], ['active' => (int)$l['active']], ['active' => $new], $reason);
        PricingService::resetCache();
        $this->ok($new ? 'Grille activée.' : 'Grille désactivée.', '/tarifs');
    }

    public function setPrice(): void
    {
        $list = Database::one('SELECT * FROM price_lists WHERE id = ?', [$this->int('list_id')]) ?? $this->fail('Grille introuvable.');
        $article = Database::one('SELECT id, name FROM articles WHERE id = ?', [$this->int('article_id')]) ?? $this->fail('Article introuvable.');
        $reason = $this->required(['reason' => 'Motif'])['reason'];
        $raw = trim($this->str('price'));
        if ($raw === '') {
            $new = null; // retire l'article de cette grille (la grille suivante s'applique)
        } elseif (ctype_digit($raw) && (int)$raw > 0 && (int)$raw <= 10_000_000) {
            $new = (int)$raw;
        } else {
            $this->fail('Prix invalide : entier positif en FCFA, ou vide pour retirer l\'article de la grille.');
        }
        try {
            $changed = (new PricingService())->setPrice($list, $article, $new, $reason);
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        }
        if (!$changed) {
            $this->ok('Aucun changement.', '/tarifs');
        }
        PricingService::resetCache();
        $this->ok('Prix enregistré (nouvelle version).', '/tarifs');
    }
}
