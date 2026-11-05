<?php

namespace App\Repository;

use App\Dto\TicketFilter;
use App\Entity\Materiel;
use App\Entity\Ticket;
use App\Service\Choices;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** Toutes les requêtes ignorent les tickets supprimés (suppression logique). */
class TicketRepository extends ServiceEntityRepository
{
    private const SORT_EXPRESSIONS = [
        'date' => 't.createdAt',
        'reference' => 't.reference',
        'titre' => 't.titre',
        'priorite' => 'prank',
        'statut' => 't.statut',
    ];

    public function __construct(ManagerRegistry $registry, private readonly Choices $choices)
    {
        parent::__construct($registry, Ticket::class);
    }

    /** Tri par gravité d'après l'ordre de la liste des priorités (de la moins à la plus urgente) : urgente en premier quand on trie « priorité » par ordre décroissant. */
    private function priorityRank(): string
    {
        $cases = '';
        foreach (array_keys($this->choices->labels('ticket_priorite')) as $i => $code) {
            $cases .= sprintf("WHEN '%s' THEN %d ", str_replace("'", "''", (string) $code), $i + 1);
        }

        return 'CASE t.priorite '.$cases.'ELSE 0 END';
    }

    public function findActive(int $id): ?Ticket
    {
        return $this->createQueryBuilder('t')->andWhere('t.id = :id')->andWhere('t.deletedAt IS NULL')
            ->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    /** @return array{0: list<Ticket>, 1: int} la page demandée et le nombre total de lignes filtrées */
    public function paginate(TicketFilter $filter, int $perPage): array
    {
        $total = (int) $this->filtered($filter)->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
        $rows = $this->filtered($filter)->addSelect($this->priorityRank().' AS HIDDEN prank')
            ->orderBy(self::SORT_EXPRESSIONS[$filter->sort], 'asc' === $filter->dir ? 'ASC' : 'DESC')->addOrderBy('t.id', 'DESC')
            ->setFirstResult(($filter->page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();

        return [$rows, $total];
    }

    /** @return list<Ticket> les tickets encore à traiter sur cet équipement, du plus récent au plus ancien */
    public function activeFor(Materiel $materiel): array
    {
        return $this->createQueryBuilder('t')->andWhere('t.materiel = :m')->andWhere('t.deletedAt IS NULL')->andWhere('t.statut IN (:actifs)')
            ->setParameter('m', $materiel)->setParameter('actifs', Ticket::ACTIFS)->orderBy('t.createdAt', 'DESC')->getQuery()->getResult();
    }

    /** @return list<Ticket> tous les tickets non supprimés de cet équipement */
    public function historyFor(Materiel $materiel): array
    {
        return $this->createQueryBuilder('t')->andWhere('t.materiel = :m')->andWhere('t.deletedAt IS NULL')
            ->setParameter('m', $materiel)->orderBy('t.createdAt', 'DESC')->addOrderBy('t.id', 'DESC')->getQuery()->getResult();
    }

    /**
     * Tableau de bord des pannes et demandes.
     *
     * @return array{open: int, inProgress: int, urgent: int, closedThisMonth: int}
     */
    public function dashboard(): array
    {
        return [
            'open' => $this->countWhere('t.statut = :s', ['s' => Ticket::OUVERT]),
            'inProgress' => $this->countWhere('t.statut = :s', ['s' => Ticket::EN_COURS]),
            'urgent' => $this->countWhere('t.statut IN (:actifs) AND t.priorite = :p', ['actifs' => Ticket::ACTIFS, 'p' => 'urgente']),
            'closedThisMonth' => $this->countWhere('t.statut = :s AND t.closedAt >= :from', ['s' => Ticket::RESOLU, 'from' => new \DateTimeImmutable('first day of this month midnight')]),
        ];
    }

    private function countWhere(string $where, array $parameters): int
    {
        $qb = $this->createQueryBuilder('t')->select('COUNT(t.id)')->andWhere('t.deletedAt IS NULL')->andWhere($where);
        foreach ($parameters as $name => $value) {
            $qb->setParameter($name, $value);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function filtered(TicketFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')->leftJoin('t.materiel', 'm')
            ->andWhere('t.type = :type')->andWhere('t.deletedAt IS NULL')->setParameter('type', $filter->type);

        if ('actifs' === $filter->etat) {
            $qb->andWhere('t.statut IN (:actifs)')->setParameter('actifs', Ticket::ACTIFS);
        } elseif ($filter->etat) {
            $qb->andWhere('t.statut = :statut')->setParameter('statut', $filter->etat);
        }
        if ($filter->priorite) {
            $qb->andWhere('t.priorite = :priorite')->setParameter('priorite', $filter->priorite);
        }
        if ('' !== $filter->q) {
            $qb->andWhere('t.reference LIKE :q OR t.titre LIKE :q OR m.designation LIKE :q OR m.reference LIKE :q')
                ->setParameter('q', '%'.addcslashes($filter->q, '%_\\').'%');
        }

        return $qb;
    }
}
