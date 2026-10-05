<?php
declare(strict_types=1);

namespace App\Services\Messaging;

/** Passerelle d'envoi d'un canal (SMS, WhatsApp, e-mail). Remplaçable : un fournisseur = une implémentation. */
interface Gateway
{
    /** 'sms' | 'whatsapp' | 'email' */
    public function channel(): string;

    /** Les paramètres du fournisseur sont-ils renseignés ? Sinon le canal est ignoré (jamais « envoyé » pour de faux). */
    public function configured(): bool;

    /**
     * @param string $to      numéro (+237…) ou adresse e-mail
     * @param string $subject utilisé par l'e-mail seulement
     * @return ?string référence du message chez le fournisseur
     * @throws \RuntimeException en cas d'échec
     */
    public function send(string $to, string $subject, string $body): ?string;
}
