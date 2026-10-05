<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Consentements marketing par canal. Historique en ajout seul : la ligne la plus récente fait foi.
 * Sans consentement enregistré, aucune offre promotionnelle ne part (RG18). Les messages opérationnels
 * (confirmation de dépôt, commande prête, retard, livraison) ne dépendent pas de ce consentement (RG11).
 */
final class ConsentService
{
    public const CHANNELS = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail'];

    public static function granted(int $clientId, string $channel): bool
    {
        return (int)Database::value("SELECT granted FROM client_consents WHERE client_id = ? AND channel = ? AND purpose = 'marketing' ORDER BY id DESC LIMIT 1", [$clientId, $channel]) === 1;
    }

    /** @return array<string,bool> canal => consentement actuel */
    public static function current(int $clientId): array
    {
        $out = array_fill_keys(array_keys(self::CHANNELS), false);
        foreach (Database::all("SELECT c.channel, c.granted FROM client_consents c JOIN (SELECT channel, MAX(id) id FROM client_consents WHERE client_id = ? AND purpose = 'marketing' GROUP BY channel) m ON m.id = c.id", [$clientId]) as $r) {
            $out[$r['channel']] = (int)$r['granted'] === 1;
        }
        return $out;
    }

    /** Enregistre un changement (rien si inchangé). @return bool true si une ligne a été ajoutée */
    public static function set(int $clientId, string $channel, bool $granted, string $source): bool
    {
        if (!isset(self::CHANNELS[$channel])) {
            throw new \DomainException('Canal inconnu.');
        }
        if (self::granted($clientId, $channel) === $granted) {
            return false;
        }
        Database::insert('client_consents', ['client_id' => $clientId, 'channel' => $channel, 'purpose' => 'marketing', 'granted' => $granted ? 1 : 0, 'source' => mb_substr($source, 0, 40), 'user_id' => Auth::id() ?: null, 'created_at' => now()]);
        Audit::log('consent.change', 'clients', $clientId, ['channel' => $channel, 'source' => $source], ['marketing' => !$granted], ['marketing' => $granted]);
        return true;
    }

    /** Applique le formulaire de la fiche client : canaux cochés = consentis, les autres = refusés (révocation tracée). */
    public static function setFromForm(int $clientId, array $checked, string $source): void
    {
        foreach (array_keys(self::CHANNELS) as $ch) {
            self::set($clientId, $ch, !empty($checked[$ch]), $source);
        }
    }

    /** @return list<array> */
    public static function history(int $clientId): array
    {
        return Database::all("SELECT c.*, u.name user_name FROM client_consents c LEFT JOIN users u ON u.id = c.user_id WHERE c.client_id = ? ORDER BY c.id DESC LIMIT 20", [$clientId]);
    }
}
