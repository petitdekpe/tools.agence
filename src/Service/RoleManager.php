<?php

namespace App\Service;

use App\Entity\Role;
use App\Entity\RolePermission;
use App\Entity\User;
use App\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** Création, suppression des rôles et génération de leur code. Les permissions sont réglées par PermissionManager. */
class RoleManager
{
    /** Codes que Symfony ou l'application utilisent déjà : un rôle créé ne doit jamais les prendre. */
    private const RESERVED = [User::ROLE_ADMIN, User::ROLE_AGENT, User::ROLE_DIRECTION, User::ROLE_USER, 'ROLE_ALLOWED_TO_SWITCH', 'ROLE_SUPER_ADMIN'];

    public function __construct(private readonly EntityManagerInterface $em, private readonly RoleRepository $roles, private readonly PermissionManager $permissions)
    {
    }

    /**
     * Enregistre un nouveau rôle et ses permissions. Son code est tiré de son nom, puis ne change plus.
     *
     * @param list<string> $permissions
     */
    public function create(Role $role, array $permissions = []): Role
    {
        $role->setCode($this->uniqueCode($role->getLabel()))->setSystem(false);
        $this->em->persist($role);
        $this->em->flush();
        $this->permissions->save($role, $permissions);

        return $role;
    }

    /** Un rôle système ne se supprime pas, et un rôle encore attribué non plus : ses utilisateurs perdraient leurs droits. */
    public function delete(Role $role): void
    {
        if ($role->isSystem()) {
            throw new \DomainException(sprintf('Le rôle « %s » fait partie de l\'application et ne peut pas être supprimé.', $role->getLabel()));
        }
        $users = $this->roles->countUsers($role);
        if ($users > 0) {
            throw new \DomainException(sprintf('Le rôle « %s » est encore attribué à %d utilisateur%s. Donnez-leur un autre rôle avant de le supprimer.', $role->getLabel(), $users, $users > 1 ? 's' : ''));
        }

        // Sans clés étrangères (MyISAM en local), rien ne supprimerait ses permissions en cascade.
        $this->em->createQuery('DELETE FROM '.RolePermission::class.' rp WHERE rp.role = :role')->setParameter('role', $role)->execute();
        $this->em->remove($role);
        $this->em->flush();
    }

    /** Crée les rôles système s'ils manquent (base neuve ou vidée). Sans effet sur ceux qui existent. */
    public function ensureSystemRoles(): void
    {
        foreach (Role::SYSTEM as $code => [$label, $description]) {
            if (!$this->roles->findOneByCode($code)) {
                $this->em->persist((new Role())->setCode($code)->setLabel($label)->setDescription($description)->setSystem(true));
            }
        }
        $this->em->flush();
    }

    public function uniqueCode(string $label): string
    {
        $slug = strtoupper(str_replace('-', '_', (new AsciiSlugger('fr'))->slug(mb_substr($label, 0, 40))->toString()));
        $base = 'ROLE_'.('' === $slug ? 'ROLE' : $slug);

        $code = $base;
        for ($i = 2; \in_array($code, self::RESERVED, true) || $this->roles->findOneByCode($code); ++$i) {
            $code = $base.'_'.$i;
        }

        return $code;
    }
}
