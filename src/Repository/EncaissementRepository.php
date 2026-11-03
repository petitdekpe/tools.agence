<?php

namespace App\Repository;

use App\Dto\EncaissementFilter;
use App\Entity\Encaissement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** Toutes les requêtes ignorent les encaissements supprimés (suppression logique). */
class EncaissementRepository extends ServiceEntityRepository
{
    private const SORT_EXPRESSIONS = [
        'date' => 'e.dateEncaissement',
        'client' => 'c.name',
        'nature' => 'e.nature',
        'ttc' => 'e.montantTtc',
        'ht' => 'e.montantHt',
        'tva_source' => 'e.tvaPreleveeSource',
        'tva_reverser' => 'e.tvaAReverser',
        'aib' => 'e.aibPreleveSource',
        'net' => 'e.montantNetRecu',
        'especes' => 'e.montantEspecesMomo',
        'cheque' => 'e.montantChequeVirement',
        'observation' => 'e.observation',
        'coherence' => 'ecart',
    ];

    private const SUM_FIELDS = [
        'ttc' => 'montantTtc',
        'ht' => 'montantHt',
        'tvaFacturee' => 'tvaFacturee',
        'tvaSource' => 'tvaPreleveeSource',
        'tvaReverser' => 'tvaAReverser',
        'aib' => 'aibPreleveSource',
        'net' => 'montantNetRecu',
        'especes' => 'montantEspecesMomo',
        'cheque' => 'montantChequeVirement',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Encaissement::class);
    }

    public function findActive(int $id): ?Encaissement
    {
        return $this->createQueryBuilder('e')->andWhere('e.id = :id')->andWhere('e.deletedAt IS NULL')
            ->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    /** @return array{0: list<Encaissement>, 1: int} la page demandée et le nombre total de lignes filtrées */
    public function paginate(EncaissementFilter $filter, int $perPage): array
    {
        $total = (int) $this->filtered($filter)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        $rows = $this->sorted($this->filtered($filter), $filter)
            ->setFirstResult(($filter->page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();

        return [$rows, $total];
    }

    /** @return list<Encaissement> toutes les lignes filtrées, pour les exports */
    public function findAllFiltered(EncaissementFilter $filter): array
    {
        return $this->sorted($this->filtered($filter), $filter)->getQuery()->getResult();
    }

    /** @return array<string, string|int> les totaux de toutes les lignes filtrées (pas seulement la page affichée) */
    public function totals(EncaissementFilter $filter): array
    {
        $qb = $this->filtered($filter)->select('COUNT(e.id) AS n');
        foreach (self::SUM_FIELDS as $alias => $field) {
            $qb->addSelect(sprintf('COALESCE(SUM(e.%s), 0) AS %s', $field, $alias));
        }
        $row = $qb->getQuery()->getSingleResult();
        $row['n'] = (int) $row['n'];

        return $row;
    }

    /** @return list<array<string, mixed>> une ligne par mois (« 2026-03 »), du plus récent au plus ancien */
    public function monthlySummary(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('SUBSTRING(e.dateEncaissement, 1, 7) AS month', 'COUNT(e.id) AS n', 'SUM(e.montantTtc) AS ttc', 'SUM(e.montantHt) AS ht', 'SUM(e.tvaAReverser) AS tvaReverser', 'SUM(e.aibPreleveSource) AS aib', 'SUM(e.montantNetRecu) AS net')
            ->groupBy('month')->orderBy('month', 'DESC');
        $this->restrictPeriod($qb, $from, $to);

        return $qb->getQuery()->getArrayResult();
    }

    /** @return list<array<string, mixed>> une ligne par nature de paiement, du plus gros TTC au plus petit */
    public function natureSummary(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('e.nature AS nature', 'COUNT(e.id) AS n', 'SUM(e.montantTtc) AS ttc', 'SUM(e.montantNetRecu) AS net')
            ->groupBy('e.nature')->orderBy('ttc', 'DESC');
        $this->restrictPeriod($qb, $from, $to);

        return $qb->getQuery()->getArrayResult();
    }

    /** @return list<array<string, mixed>> une ligne par client, du plus gros TTC au plus petit */
    public function clientSummary(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('e')->join('e.client', 'c')
            ->select('c.id AS id', 'c.name AS name', 'COUNT(e.id) AS n', 'SUM(e.montantTtc) AS ttc', 'SUM(e.montantNetRecu) AS net')
            ->groupBy('c.id', 'c.name')->orderBy('ttc', 'DESC')->addOrderBy('c.name', 'ASC');
        $this->restrictPeriod($qb, $from, $to);

        return $qb->getQuery()->getArrayResult();
    }

    private function filtered(EncaissementFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')->join('e.client', 'c')->andWhere('e.deletedAt IS NULL');
        $this->restrictPeriod($qb, $filter->from, $filter->to);

        if ($filter->client) {
            $qb->andWhere('c.id = :client')->setParameter('client', $filter->client);
        }
        if ($filter->nature) {
            $qb->andWhere('e.nature = :nature')->setParameter('nature', $filter->nature);
        }
        if ('especes' === $filter->mode) {
            $qb->andWhere('e.montantEspecesMomo > 0');
        } elseif ('virement' === $filter->mode) {
            $qb->andWhere('e.montantChequeVirement > 0');
        }
        if ('ok' === $filter->coherence) {
            $qb->andWhere('e.montantEspecesMomo + e.montantChequeVirement = e.montantNetRecu');
        } elseif ('ecart' === $filter->coherence) {
            $qb->andWhere('e.montantEspecesMomo + e.montantChequeVirement <> e.montantNetRecu');
        }
        if ('' !== $filter->q) {
            $qb->andWhere('c.name LIKE :q OR c.ifu LIKE :q')
                ->setParameter('q', '%'.addcslashes($filter->q, '%_\\').'%');
        }

        return $qb;
    }

    private function sorted(QueryBuilder $qb, EncaissementFilter $filter): QueryBuilder
    {
        $qb->select('e', 'c')->addSelect('(e.montantNetRecu - e.montantEspecesMomo - e.montantChequeVirement) AS HIDDEN ecart')
            ->orderBy(self::SORT_EXPRESSIONS[$filter->sort], 'asc' === $filter->dir ? 'ASC' : 'DESC');
        if ('date' !== $filter->sort) {
            $qb->addOrderBy('e.dateEncaissement', 'DESC');
        }

        return $qb->addOrderBy('e.id', 'DESC');
    }

    private function restrictPeriod(QueryBuilder $qb, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): void
    {
        $qb->andWhere('e.deletedAt IS NULL');
        if ($from) {
            $qb->andWhere('e.dateEncaissement >= :from')->setParameter('from', $from, 'date_immutable');
        }
        if ($to) {
            $qb->andWhere('e.dateEncaissement <= :to')->setParameter('to', $to, 'date_immutable');
        }
    }
}
