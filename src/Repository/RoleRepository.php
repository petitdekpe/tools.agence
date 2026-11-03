<?php

namespace App\Repository;

use App\Entity\Role;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Role> */
class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    public function findOneByCode(string $code): ?Role
    {
        return $this->findOneBy(['code' => $code]);
    }

    /** Rôles système d'abord, puis les rôles créés, par nom. */
    public function orderedQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('r')->orderBy('r.isSystem', 'DESC')->addOrderBy('r.label', 'ASC');
    }

    /** @return list<Role> */
    public function findAllOrdered(): array
    {
        return $this->orderedQuery()->getQuery()->getResult();
    }

    /** @return array<int, int> nombre d'utilisateurs par identifiant de rôle */
    public function userCounts(): array
    {
        $counts = [];
        foreach ($this->getEntityManager()->createQuery('SELECT IDENTITY(u.role) AS id, COUNT(u.id) AS n FROM '.User::class.' u GROUP BY u.role')->getArrayResult() as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        return $counts;
    }

    public function countUsers(Role $role): int
    {
        return (int) $this->getEntityManager()->createQuery('SELECT COUNT(u.id) FROM '.User::class.' u WHERE u.role = :role')
            ->setParameter('role', $role)->getSingleScalarResult();
    }
}
