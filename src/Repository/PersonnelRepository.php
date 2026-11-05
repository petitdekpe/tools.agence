<?php

namespace App\Repository;

use App\Dto\PersonnelFilter;
use App\Entity\Affectation;
use App\Entity\Personnel;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** Toutes les requêtes ignorent les fiches supprimées (suppression logique). */
class PersonnelRepository extends ServiceEntityRepository
{
    private const SORT_EXPRESSIONS = [
        'nom' => 'p.lastName',
        'poste' => 'p.jobTitle',
        'service' => 'p.department',
        'contrat' => 'p.contractType',
        'entree' => 'p.hiredAt',
        'statut' => 'p.leftAt',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Personnel::class);
    }

    public function findActive(int $id): ?Personnel
    {
        return $this->createQueryBuilder('p')->andWhere('p.id = :id')->andWhere('p.deletedAt IS NULL')
            ->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    /** La fiche (non supprimée) rattachée à un compte, s'il y en a une. */
    public function findForUser(User $user): ?Personnel
    {
        return $this->createQueryBuilder('p')->andWhere('p.user = :user')->andWhere('p.deletedAt IS NULL')
            ->setParameter('user', $user)->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** @return array{0: list<Personnel>, 1: int} */
    public function paginate(PersonnelFilter $filter, int $perPage): array
    {
        $total = (int) $this->filtered($filter)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
        $qb = $this->filtered($filter)->orderBy(self::SORT_EXPRESSIONS[$filter->sort], 'desc' === $filter->dir ? 'DESC' : 'ASC');
        if ('nom' !== $filter->sort) {
            $qb->addOrderBy('p.lastName', 'ASC');
        }
        $rows = $qb->addOrderBy('p.firstName', 'ASC')->addOrderBy('p.id', 'ASC')
            ->setFirstResult(($filter->page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return [$rows, $total];
    }

    /** @return list<string> les services déjà saisis, pour le filtre */
    public function departments(): array
    {
        return array_column($this->createQueryBuilder('p')->select('DISTINCT p.department AS d')
            ->andWhere('p.deletedAt IS NULL')->andWhere('p.department IS NOT NULL')->orderBy('d', 'ASC')->getQuery()->getScalarResult(), 'd');
    }

    /** @return list<Personnel> le personnel en poste, pour choisir un détenteur */
    public function findInPost(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.deletedAt IS NULL')->andWhere('p.leftAt IS NULL OR p.leftAt > :today')
            ->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable')
            ->orderBy('p.lastName', 'ASC')->addOrderBy('p.firstName', 'ASC')->getQuery()->getResult();
    }

    /**
     * Nombre de matériels non restitués, par fiche.
     *
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    public function heldCounts(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(a.personnel) AS pid', 'COUNT(a.id) AS n')
            ->from(Affectation::class, 'a')->join('a.materiel', 'm')
            ->andWhere('a.returnedAt IS NULL')->andWhere('m.deletedAt IS NULL')->andWhere('a.personnel IN (:ids)')
            ->groupBy('pid')->setParameter('ids', $ids)->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $r): array => ['pid' => (int) $r['pid'], 'n' => (int) $r['n']], $rows), 'n', 'pid');
    }

    private function filtered(PersonnelFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')->andWhere('p.deletedAt IS NULL');
        $today = new \DateTimeImmutable('today');

        if ('actif' === $filter->statut) {
            $qb->andWhere('p.leftAt IS NULL OR p.leftAt > :today')->setParameter('today', $today, 'date_immutable');
        } elseif ('parti' === $filter->statut) {
            $qb->andWhere('p.leftAt IS NOT NULL AND p.leftAt <= :today')->setParameter('today', $today, 'date_immutable');
        }
        if ('' !== $filter->service) {
            $qb->andWhere('p.department = :service')->setParameter('service', $filter->service);
        }
        if ($filter->contrat) {
            $qb->andWhere('p.contractType = :contrat')->setParameter('contrat', $filter->contrat);
        }
        if ('' !== $filter->q) {
            $qb->andWhere('p.lastName LIKE :q OR p.firstName LIKE :q OR p.jobTitle LIKE :q OR p.department LIKE :q OR p.email LIKE :q')
                ->setParameter('q', '%'.addcslashes($filter->q, '%_\\').'%');
        }

        return $qb;
    }
}
