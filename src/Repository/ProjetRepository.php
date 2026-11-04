<?php

namespace App\Repository;

use App\Dto\ProjetFilter;
use App\Entity\Projet;
use App\Entity\Tache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** Toutes les requêtes ignorent les projets supprimés (suppression logique). */
class ProjetRepository extends ServiceEntityRepository
{
    private const SORT_EXPRESSIONS = [
        'nom' => 'p.nom',
        'client' => 'c.name',
        'echeance' => 'p.echeance',
        'statut' => 'p.statut',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Projet::class);
    }

    public function findActive(int $id): ?Projet
    {
        return $this->createQueryBuilder('p')->andWhere('p.id = :id')->andWhere('p.deletedAt IS NULL')
            ->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    /**
     * Les projets du tableau, regroupés par colonne et dans l'ordre des cartes.
     *
     * @return array<string, list<Projet>> clé : statut ; toutes les colonnes sont présentes, même vides
     */
    public function board(ProjetFilter $filter): array
    {
        $filter = clone $filter;
        $filter->statut = null;
        $projects = $this->filtered($filter)->addSelect('t')->leftJoin('p.taches', 't')
            ->andWhere('p.statut IN (:colonnes)')->setParameter('colonnes', Projet::COLONNES)
            ->orderBy('p.position', 'ASC')->addOrderBy('p.id', 'ASC')->getQuery()->getResult();

        $columns = array_fill_keys(Projet::COLONNES, []);
        foreach ($projects as $project) {
            $columns[$project->getStatut()][] = $project;
        }

        return $columns;
    }

    /** @return list<Projet> les projets d'une colonne, dans l'ordre des cartes */
    public function column(string $statut): array
    {
        return $this->createQueryBuilder('p')->andWhere('p.deletedAt IS NULL')->andWhere('p.statut = :s')->setParameter('s', $statut)
            ->orderBy('p.position', 'ASC')->addOrderBy('p.id', 'ASC')->getQuery()->getResult();
    }

    public function nextPosition(string $statut): int
    {
        $max = $this->createQueryBuilder('p')->select('MAX(p.position)')->andWhere('p.deletedAt IS NULL')->andWhere('p.statut = :s')
            ->setParameter('s', $statut)->getQuery()->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }

    /** @return array{0: list<Projet>, 1: int} la page demandée et le nombre total de projets filtrés */
    public function paginate(ProjetFilter $filter, int $perPage): array
    {
        $total = (int) $this->filtered($filter)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        // Les tâches sont chargées avec chaque projet : la pagination se fait d'abord sur les identifiants.
        $order = 'avancement' === $filter->sort ? 'p.statut' : self::SORT_EXPRESSIONS[$filter->sort];
        $ids = array_map('intval', $this->filtered($filter)->select('p.id')
            ->orderBy($order, 'desc' === $filter->dir ? 'DESC' : 'ASC')->addOrderBy('p.id', 'ASC')
            ->setFirstResult(($filter->page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getSingleColumnResult());

        $rows = [] === $ids ? [] : $this->createQueryBuilder('p')->leftJoin('p.client', 'c')->addSelect('c')->leftJoin('p.responsable', 'r')->addSelect('r')
            ->leftJoin('p.taches', 't')->addSelect('t')->andWhere('p.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        $position = array_flip($ids);
        usort($rows, static fn (Projet $a, Projet $b): int => $position[$a->getId()] <=> $position[$b->getId()]);
        if ('avancement' === $filter->sort) {
            usort($rows, static fn (Projet $a, Projet $b): int => 'desc' === $filter->dir ? $b->getProgress() <=> $a->getProgress() : $a->getProgress() <=> $b->getProgress());
        }

        return [$rows, $total];
    }

    /**
     * Chiffres clés : projets en cours, en retard, tâches en retard.
     *
     * @return array{inProgress: int, late: int, lateTasks: int, openTasks: int}
     */
    public function dashboard(?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');

        $count = static fn (QueryBuilder $qb): int => (int) $qb->getQuery()->getSingleScalarResult();

        return [
            'inProgress' => $count($this->createQueryBuilder('p')->select('COUNT(p.id)')->andWhere('p.deletedAt IS NULL')->andWhere('p.statut = :s')->setParameter('s', Projet::EN_COURS)),
            'late' => $count($this->createQueryBuilder('p')->select('COUNT(p.id)')->andWhere('p.deletedAt IS NULL')->andWhere('p.statut IN (:ouverts)')
                ->andWhere('p.echeance < :today')->setParameter('ouverts', Projet::OUVERTS)->setParameter('today', $today, 'date_immutable')),
            'lateTasks' => $count($this->getEntityManager()->createQueryBuilder()->select('COUNT(t.id)')->from(Tache::class, 't')->join('t.projet', 'p')
                ->andWhere('p.deletedAt IS NULL')->andWhere('p.statut IN (:ouverts)')->andWhere('t.statut <> :done')->andWhere('t.echeance < :today')
                ->setParameter('ouverts', Projet::OUVERTS)->setParameter('done', Tache::TERMINE)->setParameter('today', $today, 'date_immutable')),
            'openTasks' => $count($this->getEntityManager()->createQueryBuilder()->select('COUNT(t.id)')->from(Tache::class, 't')->join('t.projet', 'p')
                ->andWhere('p.deletedAt IS NULL')->andWhere('p.statut IN (:ouverts)')->andWhere('t.statut <> :done')
                ->setParameter('ouverts', Projet::OUVERTS)->setParameter('done', Tache::TERMINE)),
        ];
    }

    private function filtered(ProjetFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')->leftJoin('p.client', 'c')->addSelect('c')->leftJoin('p.responsable', 'r')->addSelect('r')
            ->andWhere('p.deletedAt IS NULL');

        if ($filter->client) {
            $qb->andWhere('c.id = :client')->setParameter('client', $filter->client);
        }
        if ($filter->responsable) {
            $qb->andWhere('r.id = :responsable')->setParameter('responsable', $filter->responsable);
        }
        if ('ouverts' === $filter->statut) {
            $qb->andWhere('p.statut IN (:ouverts)')->setParameter('ouverts', Projet::OUVERTS);
        } elseif ($filter->statut) {
            $qb->andWhere('p.statut = :statut')->setParameter('statut', $filter->statut);
        }
        if ('' !== $filter->q) {
            $qb->andWhere('p.nom LIKE :q OR p.description LIKE :q OR c.name LIKE :q')->setParameter('q', '%'.addcslashes($filter->q, '%_\\').'%');
        }

        return $qb;
    }
}
