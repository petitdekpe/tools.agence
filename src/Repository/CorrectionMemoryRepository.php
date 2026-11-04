<?php

namespace App\Repository;

use App\Entity\CorrectionMemory;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CorrectionMemoryRepository extends ServiceEntityRepository
{
    /** Une entrée jamais utilisée depuis ce délai sort de la mémoire. */
    public const RETENTION = '-90 days';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorrectionMemory::class);
    }

    /**
     * Les règles de l'équipe, dans un ordre stable (le même texte à chaque appel, pour que le cache du prompt serve).
     *
     * @return list<CorrectionMemory>
     */
    public function shared(): array
    {
        return $this->createQueryBuilder('m')->andWhere('m.shared = true')->orderBy('m.id', 'ASC')->getQuery()->getResult();
    }

    /**
     * Les entrées personnelles encore vivantes qui valent pour ce dossier : les siennes et celles de tous les dossiers.
     * Sans dossier, seules celles de tous les dossiers.
     *
     * @return list<CorrectionMemory>
     */
    public function forDossier(User $user, ?string $dossier): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')->andWhere('m.shared = false')
            ->andWhere('COALESCE(m.lastUsedAt, m.createdAt) >= :cutoff')
            ->setParameter('user', $user)->setParameter('cutoff', new \DateTimeImmutable(self::RETENTION))
            ->orderBy('m.id', 'DESC')->setMaxResults(200);
        if (null === $dossier) {
            $qb->andWhere('m.dossier IS NULL');
        } else {
            $qb->andWhere('m.dossier IS NULL OR m.dossier = :dossier')->setParameter('dossier', $dossier);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Ce que l'utilisateur voit dans le panneau Mémoire : les règles de l'équipe, puis ses entrées pour ce dossier.
     *
     * @return list<CorrectionMemory>
     */
    public function visible(User $user, ?string $dossier): array
    {
        return [...$this->shared(), ...array_reverse($this->forDossier($user, $dossier))];
    }

    public function purgeStale(): void
    {
        $this->createQueryBuilder('m')->delete()
            ->andWhere('m.shared = false')->andWhere('COALESCE(m.lastUsedAt, m.createdAt) < :cutoff')
            ->setParameter('cutoff', new \DateTimeImmutable(self::RETENTION))->getQuery()->execute();
    }
}
