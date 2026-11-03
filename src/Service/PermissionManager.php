<?php

namespace App\Service;

use App\Entity\Role;
use App\Entity\RolePermission;
use App\Entity\User;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;

/** Lit et enregistre les permissions de chaque rôle. Le rôle admin a toujours toutes les permissions. */
class PermissionManager
{
    /** @var array<int, array<string, bool>>|null réglages enregistrés, par identifiant de rôle */
    private ?array $stored = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function has(User $user, string $permission): bool
    {
        $role = $user->getRole();

        return null !== $role && $this->roleHas($role, $permission);
    }

    public function roleHas(Role $role, string $permission): bool
    {
        if (!Permission::exists($permission)) {
            return false;
        }
        if ($role->isAdmin()) {
            return true;
        }

        return $this->stored()[$role->getId()][$permission] ?? \in_array($permission, Permission::DEFAULTS[$role->getCode()] ?? [], true);
    }

    /** @return list<string> les permissions accordées au rôle, dans l'ordre du catalogue */
    public function granted(Role $role): array
    {
        return array_values(array_filter(Permission::all(), fn (string $p): bool => $this->roleHas($role, $p)));
    }

    /**
     * Remplace les permissions d'un rôle. Toute permission accordée entraîne l'accès à son outil.
     * Les permissions inconnues sont ignorées ; le rôle admin ne se règle pas.
     *
     * @param list<string> $wanted
     *
     * @return array{ajoutees: list<string>, retirees: list<string>} ce qui a changé
     */
    public function save(Role $role, array $wanted): array
    {
        if ($role->isAdmin()) {
            throw new \LogicException('Le rôle admin a toutes les permissions et ne se modifie pas.');
        }
        if (null === $role->getId()) {
            throw new \LogicException('Enregistrez le rôle avant de régler ses permissions.');
        }

        $before = $this->granted($role);
        $wanted = array_values(array_intersect(Permission::all(), $wanted));
        foreach ($wanted as $permission) {
            $wanted[] = Permission::viewOf($permission);
        }
        $wanted = array_values(array_intersect(Permission::all(), array_unique($wanted)));

        $rows = [];
        foreach ($this->em->getRepository(RolePermission::class)->findBy(['role' => $role]) as $row) {
            $rows[$row->getPermission()] = $row;
        }
        foreach (Permission::all() as $permission) {
            $value = \in_array($permission, $wanted, true);
            if (isset($rows[$permission])) {
                $rows[$permission]->setGranted($value);
            } else {
                $this->em->persist(new RolePermission($role, $permission, $value));
            }
            $this->stored[$role->getId()][$permission] = $value;
        }
        $this->em->flush();

        return ['ajoutees' => array_values(array_diff($wanted, $before)), 'retirees' => array_values(array_diff($before, $wanted))];
    }

    /** @return array<int, array<string, bool>> */
    private function stored(): array
    {
        if (null === $this->stored) {
            $this->stored = [];
            foreach ($this->em->getRepository(RolePermission::class)->findAll() as $row) {
                $this->stored[$row->getRole()->getId()][$row->getPermission()] = $row->isGranted();
            }
        }

        return $this->stored;
    }
}
