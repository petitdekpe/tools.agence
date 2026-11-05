<?php

namespace App\Repository;

use App\Dto\PublicationFilter;
use App\Entity\Publication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** Toutes les requêtes ignorent les publications supprimées (suppression logique). */
class PublicationRepository extends ServiceEntityRepository
{
    private const SORT_EXPRESSIONS = [
        'date' => 'p.datePublication',
        'titre' => 'p.titre',
        'canal' => 'p.canal',
        'statut' => 'p.statut',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Publication::class);
    }

    public function findActive(int $id): ?Publication
    {
        return $this->createQueryBuilder('p')->andWhere('p.id = :id')->andWhere('p.deletedAt IS NULL')
            ->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    /** @return array{0: list<Publication>, 1: int} la page demandée et le nombre total de lignes filtrées */
    public function paginate(PublicationFilter $filter, int $perPage): array
    {
        $total = (int) $this->filtered($filter)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
        $rows = $this->filtered($filter)
            ->orderBy(self::SORT_EXPRESSIONS[$filter->sort], 'desc' === $filter->dir ? 'DESC' : 'ASC')->addOrderBy('p.heure', 'ASC')->addOrderBy('p.id', 'ASC')
            ->setFirstResult(($filter->page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();

        return [$rows, $total];
    }

    /**
     * Les publications d'une période, pour la grille mensuelle (les filtres de période du filtre sont ignorés).
     *
     * @return list<Publication> triées par date puis par heure
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to, PublicationFilter $filter): array
    {
        $filter = clone $filter;
        $filter->from = $filter->to = null;

        return $this->filtered($filter)
            ->andWhere('p.datePublication >= :from')->andWhere('p.datePublication <= :to')
            ->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')
            ->orderBy('p.datePublication', 'ASC')->addOrderBy('p.heure', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Les publications d'une période pour le flux iCalendar, annulées comprises (le client les barre).
     *
     * @return list<Publication>
     */
    public function forFeed(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->filtered(new PublicationFilter())
            ->andWhere('p.datePublication >= :from')->andWhere('p.datePublication <= :to')
            ->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')
            ->orderBy('p.datePublication', 'ASC')->addOrderBy('p.heure', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Chiffres clés du calendrier.
     *
     * @return array{toReview: int, thisWeek: int, late: int, publishedThisMonth: int}
     */
    public function dashboard(?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $monday = $today->modify('monday this week');

        return [
            'toReview' => $this->countWhere('p.statut = :s', ['s' => Publication::RELECTURE]),
            'thisWeek' => $this->countWhere('p.datePublication >= :from AND p.datePublication <= :to AND p.statut <> :annule', [
                'from' => [$monday, 'date_immutable'], 'to' => [$monday->modify('+6 days'), 'date_immutable'], 'annule' => Publication::ANNULE,
            ]),
            'late' => $this->countWhere('p.statut IN (:encours) AND p.datePublication < :today', ['encours' => Publication::EN_COURS, 'today' => [$today, 'date_immutable']]),
            'publishedThisMonth' => $this->countWhere('p.statut = :s AND p.datePublication >= :from', ['s' => Publication::PUBLIE, 'from' => [$today->modify('first day of this month'), 'date_immutable']]),
        ];
    }

    /** @param array<string, mixed> $parameters une valeur, ou [valeur, type] */
    private function countWhere(string $where, array $parameters): int
    {
        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)')->andWhere('p.deletedAt IS NULL')->andWhere($where);
        foreach ($parameters as $name => $value) {
            \is_array($value) && 2 === \count($value) && \is_string($value[1]) ? $qb->setParameter($name, $value[0], $value[1]) : $qb->setParameter($name, $value);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function filtered(PublicationFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')->leftJoin('p.client', 'c')->addSelect('c')->leftJoin('p.responsable', 'r')->addSelect('r')
            ->andWhere('p.deletedAt IS NULL');

        if ($filter->from) {
            $qb->andWhere('p.datePublication >= :ffrom')->setParameter('ffrom', $filter->from, 'date_immutable');
        }
        if ($filter->to) {
            $qb->andWhere('p.datePublication <= :fto')->setParameter('fto', $filter->to, 'date_immutable');
        }
        if ($filter->client) {
            $qb->andWhere('c.id = :client')->setParameter('client', $filter->client);
        }
        if ($filter->canal) {
            $qb->andWhere('p.canal = :canal')->setParameter('canal', $filter->canal);
        }
        if ('encours' === $filter->statut) {
            $qb->andWhere('p.statut IN (:encours)')->setParameter('encours', Publication::EN_COURS);
        } elseif ($filter->statut) {
            $qb->andWhere('p.statut = :statut')->setParameter('statut', $filter->statut);
        }
        if ($filter->responsable) {
            $qb->andWhere('r.id = :responsable')->setParameter('responsable', $filter->responsable);
        }
        if ('' !== $filter->q) {
            $qb->andWhere('p.titre LIKE :q OR p.contenu LIKE :q OR c.name LIKE :q')
                ->setParameter('q', '%'.addcslashes($filter->q, '%_\\').'%');
        }

        return $qb;
    }
}
