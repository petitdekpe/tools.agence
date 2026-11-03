<?php

namespace App\Repository;

use App\Dto\MaterielFilter;
use App\Entity\Materiel;
use App\Entity\Personnel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toutes les requêtes ignorent le matériel supprimé (suppression logique).
 *
 * Les listes joignent l'affectation ouverte et son détenteur : une seule par matériel, donc une ligne
 * par équipement. Les collections d'affectations ainsi chargées ne contiennent que l'affectation
 * ouverte ; l'historique complet se lit sur la fiche d'un matériel.
 */
class MaterielRepository extends ServiceEntityRepository
{
    private const SORT_EXPRESSIONS = [
        'designation' => 'm.designation',
        'categorie' => 'm.category',
        'reference' => 'm.reference',
        'etat' => 'm.etat',
        'detenteur' => 'p.lastName',
        'achat' => 'm.purchasedAt',
        'valeur' => 'm.purchaseValue',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Materiel::class);
    }

    public function findActive(int $id): ?Materiel
    {
        return $this->createQueryBuilder('m')->andWhere('m.id = :id')->andWhere('m.deletedAt IS NULL')
            ->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    /** Pour la contrainte d'unicité de la référence : les matériels supprimés ne comptent pas. */
    public function findActiveByReference(array $criteria): array
    {
        return $this->createQueryBuilder('m')->andWhere('m.reference = :ref')->andWhere('m.deletedAt IS NULL')
            ->setParameter('ref', $criteria['reference'])->getQuery()->getResult();
    }

    /** @return array{0: list<Materiel>, 1: int} */
    public function paginate(MaterielFilter $filter, int $perPage): array
    {
        $total = (int) $this->filtered($filter)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();
        $rows = $this->sorted($this->filtered($filter), $filter)
            ->setFirstResult(($filter->page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return [$rows, $total];
    }

    /** @return list<Materiel> */
    public function findAllFiltered(MaterielFilter $filter): array
    {
        return $this->sorted($this->filtered($filter), $filter)->getQuery()->getResult();
    }

    /** @return array{n: int, value: string, assigned: int} nombre, valeur d'achat cumulée et nombre d'affectés, filtres compris */
    public function totals(MaterielFilter $filter): array
    {
        $row = $this->filtered($filter)->select('COUNT(m.id) AS n', 'COALESCE(SUM(m.purchaseValue), 0) AS value', 'COUNT(a.id) AS assigned')
            ->getQuery()->getSingleResult();

        return ['n' => (int) $row['n'], 'value' => (string) $row['value'], 'assigned' => (int) $row['assigned']];
    }

    /** Matériel dont le détenteur est parti ou dont la fiche est supprimée : à récupérer. */
    public function countHeldByDeparted(): int
    {
        $filter = new MaterielFilter();
        $filter->affectation = 'parti';

        return $this->totals($filter)['n'];
    }

    /** @return list<Materiel> le matériel actuellement confié à cette personne */
    public function heldBy(Personnel $personnel): array
    {
        return $this->createQueryBuilder('m')->join('m.affectations', 'a', 'WITH', 'a.returnedAt IS NULL')->addSelect('a')
            ->andWhere('m.deletedAt IS NULL')->andWhere('a.personnel = :p')->setParameter('p', $personnel)
            ->orderBy('m.designation', 'ASC')->getQuery()->getResult();
    }

    private function filtered(MaterielFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.affectations', 'a', 'WITH', 'a.returnedAt IS NULL')
            ->leftJoin('a.personnel', 'p')
            ->andWhere('m.deletedAt IS NULL');

        if ($filter->categorie) {
            $qb->andWhere('m.category = :categorie')->setParameter('categorie', $filter->categorie);
        }
        if ($filter->etat) {
            $qb->andWhere('m.etat = :etat')->setParameter('etat', $filter->etat);
        }
        if ($filter->detenteur) {
            $qb->andWhere('p.id = :detenteur')->setParameter('detenteur', $filter->detenteur);
        }
        if ('disponible' === $filter->affectation) {
            $qb->andWhere('a.id IS NULL')->andWhere('m.etat <> :reforme')->setParameter('reforme', Materiel::ETAT_REFORME);
        } elseif ('affecte' === $filter->affectation) {
            $qb->andWhere('a.id IS NOT NULL');
        } elseif ('parti' === $filter->affectation) {
            $qb->andWhere('a.id IS NOT NULL')
                ->andWhere('p.deletedAt IS NOT NULL OR (p.leftAt IS NOT NULL AND p.leftAt <= :today)')
                ->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable');
        }
        if ('' !== $filter->q) {
            $qb->andWhere('m.designation LIKE :q OR m.reference LIKE :q')
                ->setParameter('q', '%'.addcslashes($filter->q, '%_\\').'%');
        }

        return $qb;
    }

    private function sorted(QueryBuilder $qb, MaterielFilter $filter): QueryBuilder
    {
        $qb->select('m', 'a', 'p')->orderBy(self::SORT_EXPRESSIONS[$filter->sort], 'desc' === $filter->dir ? 'DESC' : 'ASC');
        if ('designation' !== $filter->sort) {
            $qb->addOrderBy('m.designation', 'ASC');
        }

        return $qb->addOrderBy('m.id', 'ASC');
    }
}
