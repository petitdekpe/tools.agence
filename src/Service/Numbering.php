<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Numéro automatique « PRÉFIXE-ANNÉE-RANG » (rang sur trois chiffres) d'une entité qui porte une propriété
 * « reference » : FA-2026-001, DV-2026-001, PN-2026-001. Chaque préfixe a sa série, qui repart chaque année.
 */
class Numbering
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param class-string $entity */
    public function next(string $entity, string $prefix, \DateTimeImmutable $on): string
    {
        $prefix .= '-'.$on->format('Y').'-';

        // Les lignes supprimées logiquement comptent aussi : un numéro n'est jamais réutilisé.
        $references = $this->em->createQuery('SELECT e.reference FROM '.$entity.' e WHERE e.reference LIKE :prefix')
            ->setParameter('prefix', $prefix.'%')->getSingleColumnResult();

        $max = 0;
        foreach ($references as $reference) {
            $rank = substr((string) $reference, \strlen($prefix));
            if (ctype_digit($rank)) {
                $max = max($max, (int) $rank);
            }
        }

        return $prefix.str_pad((string) ($max + 1), 3, '0', \STR_PAD_LEFT);
    }
}
