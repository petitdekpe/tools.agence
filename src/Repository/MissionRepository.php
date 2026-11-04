<?php

namespace App\Repository;

use App\Dto\MissionFilter;
use App\Entity\Mission;
use App\Entity\Personnel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** Toutes les requêtes ignorent les missions supprimées (suppression logique). */
class MissionRepository extends ServiceEntityRepository
{
    private const SORT_EXPRESSIONS = [
        'date' => 'm.dateDebut',
        'titre' => 'm.titre',
        'lieu' => 'm.lieu',
        'statut' => 'm.statut',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mission::class);
    }

    public function findActive(int $id): ?Mission
    {
        return $this->createQueryBuilder('m')->andWhere('m.id = :id')->andWhere('m.deletedAt IS NULL')
            ->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    /** @return array{0: list<Mission>, 1: int} la page demandée et le nombre total de missions filtrées */
    public function paginate(MissionFilter $filter, int $perPage): array
    {
        $total = (int) $this->filtered($filter)->select('COUNT(DISTINCT m.id)')->getQuery()->getSingleScalarResult();
        $ids = array_map('intval', $this->filtered($filter)->select('m.id', self::SORT_EXPRESSIONS[$filter->sort].' AS HIDDEN sortkey')->distinct()
            ->orderBy(self::SORT_EXPRESSIONS[$filter->sort], 'desc' === $filter->dir ? 'DESC' : 'ASC')->addOrderBy('m.id', 'ASC')
            ->setFirstResult(($filter->page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getSingleColumnResult());

        $rows = [] === $ids ? [] : $this->withRelations()->andWhere('m.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        $position = array_flip($ids);
        usort($rows, static fn (Mission $a, Mission $b): int => $position[$a->getId()] <=> $position[$b->getId()]);

        return [$rows, $total];
    }

    /**
     * Les missions qui touchent la période, pour la grille mensuelle (la période du filtre est ignorée).
     *
     * @return list<Mission>
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to, MissionFilter $filter): array
    {
        $filter = clone $filter;
        $filter->from = $filter->to = null;
        $ids = array_map('intval', $this->filtered($filter)->select('m.id')->distinct()
            ->andWhere('m.dateDebut <= :to')->andWhere('m.dateFin >= :from')
            ->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')->getQuery()->getSingleColumnResult());

        return [] === $ids ? [] : $this->withRelations()->andWhere('m.id IN (:ids)')->setParameter('ids', $ids)
            ->orderBy('m.dateDebut', 'ASC')->addOrderBy('m.heureDebut', 'ASC')->addOrderBy('m.id', 'ASC')->getQuery()->getResult();
    }

    /**
     * Les missions qui commencent pendant la période, pour le bilan.
     *
     * @return list<Mission>
     */
    public function startingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->withRelations()->andWhere('m.dateDebut >= :from')->andWhere('m.dateDebut <= :to')
            ->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')
            ->orderBy('m.dateDebut', 'ASC')->addOrderBy('m.id', 'ASC')->getQuery()->getResult();
    }

    /**
     * Les autres missions ouvertes qui occupent cette personne pendant la période.
     *
     * @return list<Mission>
     */
    public function overlappingFor(Personnel $person, \DateTimeImmutable $from, \DateTimeImmutable $to, ?int $excludeId = null): array
    {
        $qb = $this->createQueryBuilder('m')->andWhere('m.deletedAt IS NULL')->andWhere('m.statut IN (:ouvertes)')
            ->andWhere('m.dateDebut <= :to')->andWhere('m.dateFin >= :from')
            ->andWhere('m.responsable = :p OR :p MEMBER OF m.equipe')
            ->setParameter('ouvertes', Mission::OUVERTES)->setParameter('p', $person)
            ->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')->orderBy('m.dateDebut', 'ASC');
        if (null !== $excludeId) {
            $qb->andWhere('m.id <> :self')->setParameter('self', $excludeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Chiffres clés.
     *
     * @return array{thisWeek: int, toClose: int, upcoming: int}
     */
    public function dashboard(?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $monday = $today->modify('monday this week');
        $count = static fn (QueryBuilder $qb): int => (int) $qb->getQuery()->getSingleScalarResult();

        return [
            'thisWeek' => $count($this->createQueryBuilder('m')->select('COUNT(m.id)')->andWhere('m.deletedAt IS NULL')->andWhere('m.statut <> :annulee')
                ->andWhere('m.dateDebut <= :end')->andWhere('m.dateFin >= :start')->setParameter('annulee', Mission::ANNULEE)
                ->setParameter('start', $monday, 'date_immutable')->setParameter('end', $monday->modify('+6 days'), 'date_immutable')),
            'toClose' => $count($this->createQueryBuilder('m')->select('COUNT(m.id)')->andWhere('m.deletedAt IS NULL')->andWhere('m.statut IN (:ouvertes)')
                ->andWhere('m.dateFin < :today')->setParameter('ouvertes', Mission::OUVERTES)->setParameter('today', $today, 'date_immutable')),
            'upcoming' => $count($this->createQueryBuilder('m')->select('COUNT(m.id)')->andWhere('m.deletedAt IS NULL')->andWhere('m.statut IN (:ouvertes)')
                ->andWhere('m.dateDebut >= :today')->andWhere('m.dateDebut <= :limit')->setParameter('ouvertes', Mission::OUVERTES)
                ->setParameter('today', $today, 'date_immutable')->setParameter('limit', $today->modify('+30 days'), 'date_immutable')),
        ];
    }

    private function withRelations(): QueryBuilder
    {
        return $this->createQueryBuilder('m')->leftJoin('m.projet', 'pr')->addSelect('pr')->leftJoin('m.client', 'c')->addSelect('c')
            ->leftJoin('m.responsable', 'r')->addSelect('r')->leftJoin('m.equipe', 'e')->addSelect('e')->andWhere('m.deletedAt IS NULL');
    }

    private function filtered(MissionFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('m')->leftJoin('m.projet', 'pr')->leftJoin('m.client', 'c')->andWhere('m.deletedAt IS NULL');

        if ($filter->from) {
            $qb->andWhere('m.dateFin >= :ffrom')->setParameter('ffrom', $filter->from, 'date_immutable');
        }
        if ($filter->to) {
            $qb->andWhere('m.dateDebut <= :fto')->setParameter('fto', $filter->to, 'date_immutable');
        }
        if ($filter->projet) {
            $qb->andWhere('pr.id = :projet')->setParameter('projet', $filter->projet);
        }
        if ($filter->client) {
            // Le client de la mission, ou à défaut celui de son projet.
            $qb->leftJoin('pr.client', 'pc')->andWhere('c.id = :client OR (c.id IS NULL AND pc.id = :client)')->setParameter('client', $filter->client);
        }
        if ($filter->personnel) {
            $qb->andWhere('m.responsable = :person OR :person MEMBER OF m.equipe')->setParameter('person', $this->getEntityManager()->getReference(Personnel::class, $filter->personnel));
        }
        if ('ouvertes' === $filter->statut) {
            $qb->andWhere('m.statut IN (:ouvertes)')->setParameter('ouvertes', Mission::OUVERTES);
        } elseif ('aclore' === $filter->statut) {
            $qb->andWhere('m.statut IN (:ouvertes)')->andWhere('m.dateFin < :today')
                ->setParameter('ouvertes', Mission::OUVERTES)->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable');
        } elseif ($filter->statut) {
            $qb->andWhere('m.statut = :statut')->setParameter('statut', $filter->statut);
        }
        if ('' !== $filter->q) {
            $qb->andWhere('m.titre LIKE :q OR m.lieu LIKE :q OR pr.nom LIKE :q OR c.name LIKE :q')->setParameter('q', '%'.addcslashes($filter->q, '%_\\').'%');
        }

        return $qb;
    }
}
