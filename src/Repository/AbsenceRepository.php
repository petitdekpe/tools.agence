<?php

namespace App\Repository;

use App\Dto\AbsenceFilter;
use App\Entity\Absence;
use App\Entity\Personnel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** Toutes les requêtes ignorent les absences supprimées (suppression logique). */
class AbsenceRepository extends ServiceEntityRepository
{
    private const SORT_EXPRESSIONS = [
        'debut' => 'a.dateDebut',
        'personne' => 'p.lastName',
        'type' => 'a.type',
        'jours' => 'a.jours',
        'statut' => 'a.statut',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Absence::class);
    }

    public function findActive(int $id): ?Absence
    {
        return $this->createQueryBuilder('a')->andWhere('a.id = :id')->andWhere('a.deletedAt IS NULL')
            ->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    /** @return array{0: list<Absence>, 1: int} la page demandée et le nombre total de lignes filtrées */
    public function paginate(AbsenceFilter $filter, int $perPage): array
    {
        $total = (int) $this->filtered($filter)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $rows = $this->filtered($filter)
            ->orderBy(self::SORT_EXPRESSIONS[$filter->sort], 'asc' === $filter->dir ? 'ASC' : 'DESC')->addOrderBy('a.id', 'DESC')
            ->setFirstResult(($filter->page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();

        return [$rows, $total];
    }

    /** Une absence en attente ou approuvée de la même personne qui recouvre tout ou partie de cette période. */
    public function findOverlapping(Absence $absence): ?Absence
    {
        if (!$absence->getPersonnel() || !$absence->getDateDebut() || !$absence->getDateFin()) {
            return null;
        }
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.personnel = :p')->andWhere('a.deletedAt IS NULL')->andWhere('a.statut IN (:occupants)')
            ->andWhere('a.dateDebut <= :fin')->andWhere('a.dateFin >= :debut')
            ->setParameter('p', $absence->getPersonnel())->setParameter('occupants', Absence::OCCUPANTS)
            ->setParameter('debut', $absence->getDateDebut(), 'date_immutable')->setParameter('fin', $absence->getDateFin(), 'date_immutable')
            ->setMaxResults(1);
        if (null !== $absence->getId()) {
            $qb->andWhere('a.id <> :self')->setParameter('self', $absence->getId());
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Les absences en attente ou approuvées de cette personne pendant la période.
     *
     * @return list<Absence>
     */
    public function findForPersonIn(Personnel $personnel, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('a')->andWhere('a.personnel = :p')->andWhere('a.deletedAt IS NULL')->andWhere('a.statut IN (:occupants)')
            ->andWhere('a.dateDebut <= :to')->andWhere('a.dateFin >= :from')
            ->setParameter('p', $personnel)->setParameter('occupants', Absence::OCCUPANTS)
            ->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')->orderBy('a.dateDebut', 'ASC')->getQuery()->getResult();
    }

    /**
     * Les absences en attente ou approuvées qui touchent la période, pour le calendrier d'équipe.
     *
     * @return list<Absence>
     */
    public function inRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('a')->join('a.personnel', 'p')->addSelect('p')
            ->andWhere('a.deletedAt IS NULL')->andWhere('p.deletedAt IS NULL')->andWhere('a.statut IN (:occupants)')
            ->andWhere('a.dateDebut <= :to')->andWhere('a.dateFin >= :from')
            ->setParameter('occupants', Absence::OCCUPANTS)
            ->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')
            ->orderBy('p.lastName', 'ASC')->addOrderBy('p.firstName', 'ASC')->addOrderBy('a.dateDebut', 'ASC')
            ->getQuery()->getResult();
    }

    /** Jours ouvrés approuvés d'une personne dont l'absence commence dans l'année. */
    public function daysTaken(Personnel $personnel, int $year, ?string $type = null): int
    {
        $qb = $this->createQueryBuilder('a')->select('COALESCE(SUM(a.jours), 0)')
            ->andWhere('a.personnel = :p')->andWhere('a.deletedAt IS NULL')->andWhere('a.statut = :approved')
            ->andWhere('a.dateDebut >= :from')->andWhere('a.dateDebut <= :to')
            ->setParameter('p', $personnel)->setParameter('approved', Absence::APPROUVEE)
            ->setParameter('from', new \DateTimeImmutable("$year-01-01"), 'date_immutable')->setParameter('to', new \DateTimeImmutable("$year-12-31"), 'date_immutable');
        if ($type) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Tableau de bord : demandes à traiter, absents aujourd'hui, absences approuvées à venir.
     *
     * @return array{pending: int, absentToday: list<Absence>, upcoming: int}
     */
    public function dashboard(?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');

        $pending = (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.deletedAt IS NULL')->andWhere('a.statut = :s')->setParameter('s', Absence::EN_ATTENTE)
            ->getQuery()->getSingleScalarResult();

        $absentToday = $this->createQueryBuilder('a')->join('a.personnel', 'p')->addSelect('p')
            ->andWhere('a.deletedAt IS NULL')->andWhere('p.deletedAt IS NULL')->andWhere('a.statut = :s')
            ->andWhere('a.dateDebut <= :today')->andWhere('a.dateFin >= :today')
            ->setParameter('s', Absence::APPROUVEE)->setParameter('today', $today, 'date_immutable')
            ->orderBy('p.lastName', 'ASC')->getQuery()->getResult();

        $upcoming = (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.deletedAt IS NULL')->andWhere('a.statut = :s')->andWhere('a.dateDebut > :today')->andWhere('a.dateDebut <= :limit')
            ->setParameter('s', Absence::APPROUVEE)->setParameter('today', $today, 'date_immutable')->setParameter('limit', $today->modify('+30 days'), 'date_immutable')
            ->getQuery()->getSingleScalarResult();

        return ['pending' => $pending, 'absentToday' => $absentToday, 'upcoming' => $upcoming];
    }

    private function filtered(AbsenceFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')->join('a.personnel', 'p')->addSelect('p')->andWhere('a.deletedAt IS NULL');

        if ($filter->etat) {
            $qb->andWhere('a.statut = :statut')->setParameter('statut', $filter->etat);
        }
        if ($filter->type) {
            $qb->andWhere('a.type = :type')->setParameter('type', $filter->type);
        }
        if ($filter->personnel) {
            $qb->andWhere('p.id = :personnel')->setParameter('personnel', $filter->personnel);
        }
        if ($filter->annee) {
            $qb->andWhere('a.dateDebut >= :from')->andWhere('a.dateDebut <= :to')
                ->setParameter('from', new \DateTimeImmutable($filter->annee.'-01-01'), 'date_immutable')
                ->setParameter('to', new \DateTimeImmutable($filter->annee.'-12-31'), 'date_immutable');
        }

        return $qb;
    }
}
