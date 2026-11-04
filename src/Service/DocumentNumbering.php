<?php

namespace App\Service;

use App\Entity\Document;

/** Numéro automatique d'un document : préfixe du type, année d'émission et rang sur trois chiffres (FA-2026-001). */
class DocumentNumbering
{
    public function __construct(private readonly Numbering $numbering)
    {
    }

    public function next(string $type, \DateTimeImmutable $issuedAt): string
    {
        return $this->numbering->next(Document::class, Document::PREFIXES[$type] ?? 'DOC', $issuedAt);
    }
}
