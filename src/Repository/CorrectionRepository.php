<?php

namespace App\Repository;

use App\Entity\Correction;
use App\Entity\CorrectionMemory;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CorrectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Correction::class);
    }

    /**
     * Supprime une relecture après avoir détaché ce qui la référence (versions révisées, entrées de mémoire) :
     * ne dépend pas des clés étrangères de la base, absentes sous MyISAM.
     */
    public function remove(Correction $correction): void
    {
        $em = $this->getEntityManager();
        $em->createQuery('UPDATE '.Correction::class.' c SET c.previous = NULL WHERE c.previous = :c')->setParameter('c', $correction)->execute();
        $em->createQuery('UPDATE '.CorrectionMemory::class.' m SET m.source = NULL, m.sourceRemark = NULL WHERE m.source = :c')->setParameter('c', $correction)->execute();
        $em->remove($correction);
        $em->flush();
    }

    /**
     * Une page du fil de l'utilisateur : les relectures les plus récentes avant `$before`, rendues de la plus ancienne à la plus récente.
     *
     * @param string|null $dossier        ne garder que ce dossier
     * @param bool        $withoutDossier ne garder que les relectures sans dossier (prime sur `$dossier`)
     *
     * @return array{0: list<Correction>, 1: bool} la page et s'il reste des relectures plus anciennes
     */
    public function thread(User $user, ?int $before, int $limit, ?string $dossier = null, bool $withoutDossier = false): array
    {
        $qb = $this->createQueryBuilder('c')->andWhere('c.user = :user')->setParameter('user', $user)
            ->orderBy('c.id', 'DESC')->setMaxResults($limit + 1);
        if ($before) {
            $qb->andWhere('c.id < :before')->setParameter('before', $before);
        }
        if ($withoutDossier) {
            $qb->andWhere('c.dossier IS NULL');
        } elseif (null !== $dossier) {
            $qb->andWhere('c.dossier = :dossier')->setParameter('dossier', $dossier);
        }

        $items = $qb->getQuery()->getResult();
        $more = \count($items) > $limit;

        return [array_reverse(\array_slice($items, 0, $limit)), $more];
    }

    /**
     * Les dernières relectures d'un dossier, de la plus récente à la plus ancienne.
     *
     * @param list<int> $exclude identifiants à écarter
     *
     * @return list<Correction>
     */
    public function recentInDossier(User $user, string $dossier, int $limit, array $exclude = []): array
    {
        $qb = $this->createQueryBuilder('c')->andWhere('c.user = :user')->andWhere('c.dossier = :dossier')
            ->setParameter('user', $user)->setParameter('dossier', $dossier)->orderBy('c.id', 'DESC')->setMaxResults($limit);
        if ($exclude) {
            $qb->andWhere('c.id NOT IN (:exclude)')->setParameter('exclude', $exclude);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Les dossiers de l'utilisateur, avec leur nombre de relectures et la date de la dernière ; ceux qui n'ont que des entrées de mémoire en font partie.
     *
     * @return list<array{name: string, count: int, last: string}>
     */
    public function dossiers(User $user): array
    {
        $rows = $this->createQueryBuilder('c')->select('c.dossier AS name', 'COUNT(c.id) AS n', 'MAX(c.createdAt) AS last')
            ->andWhere('c.user = :user')->andWhere('c.dossier IS NOT NULL')->setParameter('user', $user)
            ->groupBy('c.dossier')->orderBy('last', 'DESC')->getQuery()->getArrayResult();

        $dossiers = [];
        foreach ($rows as $row) {
            $dossiers[mb_strtolower($row['name'])] = ['name' => $row['name'], 'count' => (int) $row['n'], 'last' => (string) $row['last']];
        }

        $memory = $this->getEntityManager()->createQueryBuilder()->select('DISTINCT m.dossier')->from(CorrectionMemory::class, 'm')
            ->andWhere('m.user = :user')->andWhere('m.dossier IS NOT NULL')->setParameter('user', $user)->getQuery()->getSingleColumnResult();
        foreach ($memory as $name) {
            $dossiers[mb_strtolower($name)] ??= ['name' => $name, 'count' => 0, 'last' => ''];
        }

        return array_values($dossiers);
    }

    /**
     * Consommation de l'API depuis une date, par utilisateur, pour l'administration.
     *
     * @return list<array{name: string, email: string, n: int, input: int, output: int, cacheRead: int, cacheWrite: int}>
     */
    public function usageByUser(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('c')->join('c.user', 'u')
            ->select('u.fullName AS name', 'u.email AS email', 'COUNT(c.id) AS n', 'COALESCE(SUM(c.inputTokens), 0) AS input', 'COALESCE(SUM(c.outputTokens), 0) AS output', 'COALESCE(SUM(c.cacheReadTokens), 0) AS cacheRead', 'COALESCE(SUM(c.cacheWriteTokens), 0) AS cacheWrite')
            ->andWhere('c.createdAt >= :since')->setParameter('since', $since)
            ->groupBy('u.id', 'u.fullName', 'u.email')->orderBy('n', 'DESC')->getQuery()->getArrayResult();

        return array_map(static fn (array $r): array => [
            'name' => $r['name'], 'email' => $r['email'], 'n' => (int) $r['n'],
            'input' => (int) $r['input'], 'output' => (int) $r['output'], 'cacheRead' => (int) $r['cacheRead'], 'cacheWrite' => (int) $r['cacheWrite'],
        ], $rows);
    }
}
