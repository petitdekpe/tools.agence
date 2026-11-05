<?php

namespace App\Repository;

use App\Entity\LinkTool;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LinkTool> */
class LinkToolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkTool::class);
    }

    /** @return list<LinkTool> dans l'ordre d'affichage */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function nextPosition(): int
    {
        $max = $this->createQueryBuilder('l')->select('MAX(l.position)')->getQuery()->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }

    /** Monte ($direction < 0) ou descend ($direction > 0) un lien d'un cran, puis renumérote la liste. */
    public function move(LinkTool $link, int $direction): void
    {
        $all = $this->findAllOrdered();
        $index = array_search($link, $all, true);
        $target = false === $index ? null : $index + ($direction < 0 ? -1 : 1);
        if (null !== $target && isset($all[$target])) {
            [$all[$index], $all[$target]] = [$all[$target], $all[$index]];
        }
        foreach ($all as $i => $item) {
            $item->setPosition($i);
        }
        $this->getEntityManager()->flush();
    }
}
