<?php

namespace App\Controller\Admin;

use App\Entity\Correction;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\RoleRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DashboardController extends AbstractController
{
    #[IsGranted(Permission::ADMIN_ACCESS)]
    #[Route('/admin', name: 'app_admin')]
    public function index(EntityManagerInterface $em, RoleRepository $roles): Response
    {
        $users = $em->getRepository(User::class);
        $corrections = $em->getRepository(Correction::class);

        $counts = $roles->userCounts();
        $byRole = array_map(static fn (Role $role): array => ['id' => $role->getId(), 'code' => $role->getCode(), 'label' => $role->getLabel(), 'n' => $counts[$role->getId()] ?? 0], $roles->findAllOrdered());

        $recentLogins = $em->createQuery('SELECT u FROM '.User::class.' u WHERE u.lastLoginAt IS NOT NULL ORDER BY u.lastLoginAt DESC')
            ->setMaxResults(5)->getResult();

        $topUsers = $em->createQuery(
            'SELECT u.fullName AS name, u.email AS email, COUNT(c.id) AS n FROM '.Correction::class.' c JOIN c.user u GROUP BY u.id, u.fullName, u.email ORDER BY n DESC'
        )->setMaxResults(5)->getArrayResult();

        // Consommation de l'API sur 30 jours : la part des tokens d'entrée relue dans le cache montre si le prompt est bien mis en cache.
        $usage = $em->getRepository(Correction::class)->usageByUser(new \DateTimeImmutable('-30 days'));
        $total = ['n' => 0, 'input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0];
        foreach ($usage as $row) {
            foreach ($total as $key => $_) {
                $total[$key] += $row[$key];
            }
        }
        $total['cacheShare'] = self::cacheShare($total);
        $usage = array_map(static fn (array $row): array => $row + ['cacheShare' => self::cacheShare($row)], $usage);

        return $this->render('admin/dashboard.html.twig', [
            'usage' => $usage,
            'usageTotal' => $total,
            'totalUsers' => $users->count([]),
            'activeUsers' => $users->count(['active' => true]),
            'byRole' => $byRole,
            'totalCorrections' => $corrections->count([]),
            'weekCorrections' => (int) $em->createQuery('SELECT COUNT(c.id) FROM '.Correction::class.' c WHERE c.createdAt >= :d')
                ->setParameter('d', new \DateTimeImmutable('-7 days'))->getSingleScalarResult(),
            'recentLogins' => $recentLogins,
            'topUsers' => $topUsers,
        ]);
    }

    /** Part des tokens d'entrée lue dans le cache, en pourcentage ; null sans donnée. */
    private static function cacheShare(array $row): ?int
    {
        $input = $row['input'] + $row['cacheRead'] + $row['cacheWrite'];

        return $input > 0 ? (int) round($row['cacheRead'] / $input * 100) : null;
    }
}
