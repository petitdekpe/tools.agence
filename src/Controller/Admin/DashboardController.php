<?php

namespace App\Controller\Admin;

use App\Entity\Correction;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'app_admin')]
    public function index(EntityManagerInterface $em): Response
    {
        $users = $em->getRepository(User::class);
        $corrections = $em->getRepository(Correction::class);

        $byRole = array_fill_keys(array_keys(User::ROLE_LABELS), 0);
        foreach ($em->createQuery('SELECT u.role AS role, COUNT(u.id) AS n FROM '.User::class.' u GROUP BY u.role')->getArrayResult() as $row) {
            $byRole[$row['role']] = (int) $row['n'];
        }

        $recentLogins = $em->createQuery('SELECT u FROM '.User::class.' u WHERE u.lastLoginAt IS NOT NULL ORDER BY u.lastLoginAt DESC')
            ->setMaxResults(5)->getResult();

        $topUsers = $em->createQuery(
            'SELECT u.fullName AS name, u.email AS email, COUNT(c.id) AS n FROM '.Correction::class.' c JOIN c.user u GROUP BY u.id, u.fullName, u.email ORDER BY n DESC'
        )->setMaxResults(5)->getArrayResult();

        return $this->render('admin/dashboard.html.twig', [
            'totalUsers' => $users->count([]),
            'activeUsers' => $users->count(['active' => true]),
            'byRole' => $byRole,
            'roleLabels' => User::ROLE_LABELS,
            'totalCorrections' => $corrections->count([]),
            'weekCorrections' => (int) $em->createQuery('SELECT COUNT(c.id) FROM '.Correction::class.' c WHERE c.createdAt >= :d')
                ->setParameter('d', new \DateTimeImmutable('-7 days'))->getSingleScalarResult(),
            'recentLogins' => $recentLogins,
            'topUsers' => $topUsers,
        ]);
    }
}
