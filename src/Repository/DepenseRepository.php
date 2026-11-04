<?php

namespace App\Repository;

use App\Dto\DepenseFilter;
use App\Entity\Depense;
use App\Service\EncaissementCalculator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** Toutes les requêtes ignorent les dépenses supprimées (suppression logique). */
class DepenseRepository extends ServiceEntityRepository
{
    private const SORT_EXPRESSIONS = [
        'date' => 'd.dateDepense',
        'fournisseur' => 'd.fournisseur',
        'categorie' => 'd.categorie',
        'ttc' => 'd.montantTtc',
        'echeance' => 'd.echeance',
        'paiement' => 'd.datePaiement',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Depense::class);
    }

    public function findActive(int $id): ?Depense
    {
        return $this->createQueryBuilder('d')->andWhere('d.id = :id')->andWhere('d.deletedAt IS NULL')
            ->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    /** @return array{0: list<Depense>, 1: int} la page demandée et le nombre total de lignes filtrées */
    public function paginate(DepenseFilter $filter, int $perPage): array
    {
        $total = (int) $this->filtered($filter)->select('COUNT(d.id)')->getQuery()->getSingleScalarResult();
        $rows = $this->filtered($filter)
            ->orderBy(self::SORT_EXPRESSIONS[$filter->sort], 'asc' === $filter->dir ? 'ASC' : 'DESC')->addOrderBy('d.id', 'DESC')
            ->setFirstResult(($filter->page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();

        return [$rows, $total];
    }

    /** @return array{n: int, ttc: string, ht: string, tva: string} les totaux de toutes les lignes filtrées (pas seulement la page affichée) */
    public function totals(DepenseFilter $filter): array
    {
        $row = $this->filtered($filter)
            ->select('COUNT(d.id) AS n', 'COALESCE(SUM(d.montantTtc), 0) AS ttc', 'COALESCE(SUM(d.montantHt), 0) AS ht', 'COALESCE(SUM(d.montantTva), 0) AS tva')
            ->getQuery()->getSingleResult();

        return ['n' => (int) $row['n'], 'ttc' => (string) $row['ttc'], 'ht' => (string) $row['ht'], 'tva' => (string) $row['tva']];
    }

    /** @return list<string> les fournisseurs déjà saisis, pour la saisie semi-automatique */
    public function suppliers(): array
    {
        return $this->createQueryBuilder('d')->select('DISTINCT d.fournisseur')->andWhere('d.deletedAt IS NULL')->orderBy('d.fournisseur', 'ASC')
            ->getQuery()->getSingleColumnResult();
    }

    /** @return list<array<string, mixed>> une ligne par mois (« 2026-03 ») de dépenses, selon la date de la dépense */
    public function monthlyExpenses(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('SUBSTRING(d.dateDepense, 1, 7) AS month', 'COUNT(d.id) AS n', 'SUM(d.montantTtc) AS ttc', 'SUM(d.montantHt) AS ht', 'SUM(d.montantTva) AS tva')
            ->andWhere('d.deletedAt IS NULL')->groupBy('month');
        $this->restrict($qb, 'd.dateDepense', $from, $to);

        return $qb->getQuery()->getArrayResult();
    }

    /** @return list<array<string, mixed>> une ligne par mois de décaissements, selon la date de paiement */
    public function monthlyPaid(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('SUBSTRING(d.datePaiement, 1, 7) AS month', 'COUNT(d.id) AS n', 'SUM(d.montantTtc) AS ttc')
            ->andWhere('d.deletedAt IS NULL')->andWhere('d.datePaiement IS NOT NULL')->groupBy('month');
        $this->restrict($qb, 'd.datePaiement', $from, $to);

        return $qb->getQuery()->getArrayResult();
    }

    /** @return list<array<string, mixed>> une ligne par catégorie, de la plus grosse dépense à la plus petite */
    public function categorySummary(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.categorie AS categorie', 'COUNT(d.id) AS n', 'SUM(d.montantTtc) AS ttc', 'SUM(d.montantTva) AS tva')
            ->andWhere('d.deletedAt IS NULL')->groupBy('d.categorie')->orderBy('ttc', 'DESC');
        $this->restrict($qb, 'd.dateDepense', $from, $to);

        return $qb->getQuery()->getArrayResult();
    }

    /** @return list<array<string, mixed>> une ligne par fournisseur, de la plus grosse dépense à la plus petite */
    public function supplierSummary(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.fournisseur AS fournisseur', 'COUNT(d.id) AS n', 'SUM(d.montantTtc) AS ttc')
            ->andWhere('d.deletedAt IS NULL')->groupBy('d.fournisseur')->orderBy('ttc', 'DESC')->setMaxResults($limit);
        $this->restrict($qb, 'd.dateDepense', $from, $to);

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Chiffres clés : dépenses du mois, TVA déductible du mois, reste à payer et retards.
     *
     * @return array{monthTtc: int, monthTva: int, monthCount: int, unpaid: int, unpaidCount: int, overdue: int, overdueCount: int}
     */
    public function dashboard(?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $first = $today->modify('first day of this month');

        $month = $this->createQueryBuilder('d')->select('COUNT(d.id) AS n', 'COALESCE(SUM(d.montantTtc), 0) AS ttc', 'COALESCE(SUM(d.montantTva), 0) AS tva')
            ->andWhere('d.deletedAt IS NULL')->andWhere('d.dateDepense >= :first')->andWhere('d.dateDepense <= :last')
            ->setParameter('first', $first, 'date_immutable')->setParameter('last', $first->modify('last day of this month'), 'date_immutable')
            ->getQuery()->getSingleResult();

        $unpaid = $this->createQueryBuilder('d')->select('COUNT(d.id) AS n', 'COALESCE(SUM(d.montantTtc), 0) AS ttc')
            ->andWhere('d.deletedAt IS NULL')->andWhere('d.datePaiement IS NULL')->getQuery()->getSingleResult();

        $late = $this->createQueryBuilder('d')->select('COUNT(d.id) AS n', 'COALESCE(SUM(d.montantTtc), 0) AS ttc')
            ->andWhere('d.deletedAt IS NULL')->andWhere('d.datePaiement IS NULL')->andWhere('d.echeance < :today')
            ->setParameter('today', $today, 'date_immutable')->getQuery()->getSingleResult();

        return [
            'monthTtc' => EncaissementCalculator::toInt((string) $month['ttc']),
            'monthTva' => EncaissementCalculator::toInt((string) $month['tva']),
            'monthCount' => (int) $month['n'],
            'unpaid' => EncaissementCalculator::toInt((string) $unpaid['ttc']),
            'unpaidCount' => (int) $unpaid['n'],
            'overdue' => EncaissementCalculator::toInt((string) $late['ttc']),
            'overdueCount' => (int) $late['n'],
        ];
    }

    private function restrict(QueryBuilder $qb, string $field, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): void
    {
        if ($from) {
            $qb->andWhere("$field >= :rfrom")->setParameter('rfrom', $from, 'date_immutable');
        }
        if ($to) {
            $qb->andWhere("$field <= :rto")->setParameter('rto', $to, 'date_immutable');
        }
    }

    private function filtered(DepenseFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d')->andWhere('d.deletedAt IS NULL');
        $this->restrict($qb, 'd.dateDepense', $filter->from, $filter->to);

        if ($filter->categorie) {
            $qb->andWhere('d.categorie = :categorie')->setParameter('categorie', $filter->categorie);
        }
        if ($filter->mode) {
            $qb->andWhere('d.mode = :mode')->setParameter('mode', $filter->mode);
        }
        if ('payee' === $filter->etat) {
            $qb->andWhere('d.datePaiement IS NOT NULL');
        } elseif ('apayer' === $filter->etat) {
            $qb->andWhere('d.datePaiement IS NULL');
        } elseif ('retard' === $filter->etat) {
            $qb->andWhere('d.datePaiement IS NULL')->andWhere('d.echeance < :today')->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable');
        }
        if ('' !== $filter->q) {
            $qb->andWhere('d.fournisseur LIKE :q OR d.libelle LIKE :q OR d.numeroPiece LIKE :q')
                ->setParameter('q', '%'.addcslashes($filter->q, '%_\\').'%');
        }

        return $qb;
    }
}
