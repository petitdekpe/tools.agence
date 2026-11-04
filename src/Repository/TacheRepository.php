<?php

namespace App\Repository;

use App\Entity\Projet;
use App\Entity\Tache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tache::class);
    }

    /** Une tâche d'un projet encore actif (non supprimé). */
    public function findActive(int $id): ?Tache
    {
        return $this->createQueryBuilder('t')->join('t.projet', 'p')->addSelect('p')
            ->andWhere('t.id = :id')->andWhere('p.deletedAt IS NULL')->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    /**
     * Les tâches du projet, regroupées par colonne et dans l'ordre des cartes.
     *
     * @return array<string, list<Tache>> clé : statut ; toutes les colonnes sont présentes, même vides
     */
    public function board(Projet $projet): array
    {
        $tasks = $this->createQueryBuilder('t')->leftJoin('t.assignee', 'a')->addSelect('a')
            ->andWhere('t.projet = :p')->setParameter('p', $projet)->orderBy('t.position', 'ASC')->addOrderBy('t.id', 'ASC')->getQuery()->getResult();

        $columns = array_fill_keys(array_keys(Tache::STATUTS), []);
        foreach ($tasks as $task) {
            $columns[$task->getStatut()][] = $task;
        }

        return $columns;
    }

    /** @return list<Tache> les tâches d'une colonne du projet, dans l'ordre des cartes */
    public function column(Projet $projet, string $statut): array
    {
        return $this->createQueryBuilder('t')->andWhere('t.projet = :p')->andWhere('t.statut = :s')->setParameter('p', $projet)->setParameter('s', $statut)
            ->orderBy('t.position', 'ASC')->addOrderBy('t.id', 'ASC')->getQuery()->getResult();
    }

    public function nextPosition(Projet $projet, string $statut): int
    {
        $max = $this->createQueryBuilder('t')->select('MAX(t.position)')->andWhere('t.projet = :p')->andWhere('t.statut = :s')
            ->setParameter('p', $projet)->setParameter('s', $statut)->getQuery()->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }

    /**
     * Les tâches passées à « terminé » pendant la période, dans des projets non supprimés.
     *
     * @return list<Tache>
     */
    public function closedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('t')->join('t.projet', 'p')->addSelect('p')->leftJoin('t.assignee', 'a')->addSelect('a')
            ->andWhere('p.deletedAt IS NULL')->andWhere('t.statut = :done')->andWhere('t.closedAt >= :from')->andWhere('t.closedAt < :to')
            ->setParameter('done', Tache::TERMINE)->setParameter('from', $from)->setParameter('to', $to->modify('+1 day'))
            ->orderBy('p.nom', 'ASC')->addOrderBy('t.closedAt', 'ASC')->getQuery()->getResult();
    }

    /**
     * Les tâches ouvertes dont l'échéance est dépassée, dans des projets encore ouverts.
     *
     * @return list<Tache>
     */
    public function overdue(?\DateTimeImmutable $today = null): array
    {
        return $this->createQueryBuilder('t')->join('t.projet', 'p')->addSelect('p')->leftJoin('t.assignee', 'a')->addSelect('a')
            ->andWhere('p.deletedAt IS NULL')->andWhere('p.statut IN (:ouverts)')->andWhere('t.statut <> :done')->andWhere('t.echeance < :today')
            ->setParameter('ouverts', Projet::OUVERTS)->setParameter('done', Tache::TERMINE)->setParameter('today', $today ?? new \DateTimeImmutable('today'), 'date_immutable')
            ->orderBy('t.echeance', 'ASC')->getQuery()->getResult();
    }
}
