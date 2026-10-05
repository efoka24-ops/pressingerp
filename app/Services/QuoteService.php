<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\ServiceLevel;

/**
 * Devis : estimation chiffrée avec les tarifs en vigueur pour ce client, valable un nombre de jours limité.
 * Un devis n'engage pas la réception : la commande se crée normalement, au tarif du jour de dépôt.
 */
final class QuoteService
{
    public const STATUS = ['envoye' => 'Envoyé', 'accepte' => 'Accepté', 'refuse' => 'Refusé', 'expire' => 'Expiré'];

    /** @param list<array<string,mixed>> $lines lignes {article_id, qty} */
    public function create(int $clientId, string $level, array $lines, int $validDays = 15, string $notes = ''): int
    {
        $client = Database::one('SELECT * FROM clients WHERE id = ?', [$clientId]) ?? throw new \DomainException('Client introuvable.');
        $lines = array_values(array_filter($lines, fn($l) => is_array($l) && !empty($l['article_id'])));
        if (!$lines) {
            throw new \DomainException('Ajoutez au moins un article.');
        }
        if ($validDays < 1 || $validDays > 90) {
            throw new \DomainException('Validité du devis : 1 à 90 jours.');
        }
        $agency = Auth::agencyId();
        $q = (new PricingService())->quote($clientId, ServiceLevel::tryFrom($level) ?? ServiceLevel::Standard, $lines, false, $agency);
        return Database::transaction(function () use ($client, $q, $level, $validDays, $notes, $agency): int {
            $code = (string)Database::value('SELECT code FROM agencies WHERE id = ?', [$agency]) ?: 'XX';
            $id = Database::insert('quotes', [
                'number' => Numbering::next('quote.' . $agency, 'DV-' . $code . '-%d-%05d'), 'client_id' => $client['id'], 'agency_id' => $agency, 'status' => 'envoye',
                'valid_until' => date('Y-m-d', strtotime("+$validDays days")), 'service_level' => $level, 'subtotal' => $q['subtotal'], 'surcharge' => $q['surcharge'],
                'discount' => $q['discount'], 'total' => $q['total'], 'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null, 'created_by' => Auth::id() ?: null, 'created_at' => now(),
            ]);
            foreach ($q['lines'] as $l) {
                Database::insert('quote_lines', [
                    'quote_id' => $id, 'article_id' => $l['article_id'], 'label' => mb_substr($l['label'], 0, 150), 'qty' => $l['qty'],
                    'unit_price' => (int)round($l['price'] / max(0.01, (float)$l['qty'])), 'line_total' => $l['price'],
                ]);
            }
            Audit::log('quote.create', 'quotes', $id, ['total' => $q['total'], 'client' => (int)$client['id']]);
            return $id;
        });
    }

    /** Le client accepte ou refuse ; un devis dont la date de validité est passée ne peut plus être accepté. */
    public function decide(int $id, string $decision, string $note = ''): void
    {
        if (!in_array($decision, ['accepte', 'refuse'], true)) {
            throw new \DomainException('Décision invalide.');
        }
        $q = Database::one('SELECT * FROM quotes WHERE id = ?', [$id]) ?? throw new \DomainException('Devis introuvable.');
        if ($q['status'] !== 'envoye') {
            throw new \DomainException('Ce devis a déjà reçu une réponse ou a expiré.');
        }
        if ($q['valid_until'] < date('Y-m-d')) {
            Database::update('quotes', ['status' => 'expire'], 'id = :id', ['id' => $id]);
            throw new \DomainException('Ce devis a expiré le ' . date('d/m/Y', strtotime($q['valid_until'])) . '.');
        }
        if ($decision === 'refuse' && mb_strlen(trim($note)) < 3) {
            throw new \DomainException('Indiquez la raison du refus.');
        }
        Database::update('quotes', ['status' => $decision, 'decided_at' => now(), 'decided_note' => $note !== '' ? mb_substr($note, 0, 255) : null], 'id = :id', ['id' => $id]);
        Audit::log('quote.' . $decision, 'quotes', $id);
    }

    /** Passe en « expiré » les devis dont la validité est dépassée. */
    public static function expireOld(): int
    {
        return Database::run("UPDATE quotes SET status = 'expire' WHERE status = 'envoye' AND valid_until < CURDATE()")->rowCount();
    }
}
