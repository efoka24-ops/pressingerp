<?php
declare(strict_types=1);

namespace App\Services;

/** Aucun tarif applicable pour un article (cas SE4 : le responsable doit en être informé). */
final class PricingMissing extends \DomainException
{
    public function __construct(public readonly int $articleId, public readonly string $articleName)
    {
        parent::__construct("Aucun tarif pour « $articleName ». Le responsable a été prévenu : l'article ne peut pas être enregistré.");
    }
}
