<?php

namespace App\Controller\Admin;

use App\Entity\ActivityLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Journal des actions : consultable par l'admin et la direction. */
class ActivityController extends AbstractController
{
    private const PER_PAGE = 50;

    #[Route('/admin/activity', name: 'app_admin_activity', methods: 'GET')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $userId = $request->query->getInt('user') ?: null;
        $category = (string) $request->query->get('category');
        $action = (string) $request->query->get('action');
        $from = $this->date($request->query->get('from'));
        $to = $this->date($request->query->get('to'));
        $page = max(1, $request->query->getInt('page', 1));

        $qb = $em->createQueryBuilder()->select('l')->from(ActivityLog::class, 'l')->orderBy('l.createdAt', 'DESC')->addOrderBy('l.id', 'DESC');
        if ($userId) {
            $qb->andWhere('l.user = :user')->setParameter('user', $userId);
        }
        if (isset(ActivityLog::LABELS[$action])) {
            $qb->andWhere('l.action = :action')->setParameter('action', $action);
        } elseif (isset(ActivityLog::CATEGORIES[$category])) {
            $qb->andWhere('l.action LIKE :cat')->setParameter('cat', $category.'.%');
        }
        if ($from) {
            $qb->andWhere('l.createdAt >= :from')->setParameter('from', $from);
        }
        if ($to) {
            $qb->andWhere('l.createdAt < :to')->setParameter('to', $to->modify('+1 day'));
        }

        $total = (int) (clone $qb)->select('COUNT(l.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $logs = $qb->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)->getQuery()->getResult();

        return $this->render('admin/activity.html.twig', [
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'users' => $em->getRepository(User::class)->findBy([], ['fullName' => 'ASC']),
            'categories' => ActivityLog::CATEGORIES,
            'actions' => ActivityLog::LABELS,
            'filters' => ['user' => $userId, 'category' => $category, 'action' => $action, 'from' => $request->query->get('from'), 'to' => $request->query->get('to')],
        ]);
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $date ?: null;
    }
}
