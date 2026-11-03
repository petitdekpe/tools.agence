<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Réglage explicite d'une permission pour un rôle. Sans ligne, la valeur par défaut de Permission::DEFAULTS s'applique (rôles système). */
#[ORM\Entity]
#[ORM\Table(name: 'role_permission')]
#[ORM\UniqueConstraint(columns: ['role_id', 'permission'])]
class RolePermission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Role $role;

    #[ORM\Column(length: 40)]
    private string $permission;

    #[ORM\Column]
    private bool $granted;

    public function __construct(Role $role, string $permission, bool $granted)
    {
        $this->role = $role;
        $this->permission = $permission;
        $this->granted = $granted;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getPermission(): string
    {
        return $this->permission;
    }

    public function isGranted(): bool
    {
        return $this->granted;
    }

    public function setGranted(bool $granted): static
    {
        $this->granted = $granted;

        return $this;
    }
}
