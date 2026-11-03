<?php

namespace App\Entity;

use App\Repository\RoleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un rôle d'utilisateur. Les trois rôles d'origine sont « système » : ils ne se suppriment pas et leur code,
 * sur lequel s'appuie la sécurité, ne change jamais. Les permissions d'un rôle sont dans RolePermission.
 */
#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'app_role')]
#[UniqueEntity('label', message: 'Un rôle porte déjà ce nom.')]
class Role
{
    /** Rôles livrés avec l'application : code => [nom, description]. */
    public const SYSTEM = [
        User::ROLE_ADMIN => ['Admin', 'Accès complet : utilisateurs, rôles, réglages et tous les outils. Ce rôle ne peut ni être restreint ni supprimé.'],
        User::ROLE_DIRECTION => ['Direction', 'Utilise les outils, gère le personnel et consulte l\'administration en lecture seule.'],
        User::ROLE_AGENT => ['Agent', 'Utilise les outils au quotidien, sans accès à l\'administration.'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant technique (ROLE_CHEF_DE_PROJET), généré à la création et jamais modifié. */
    #[ORM\Column(length: 60, unique: true)]
    private string $code = '';

    #[ORM\Column(length: 60, unique: true)]
    #[Assert\NotBlank(message: 'Le nom du rôle est obligatoire.')]
    #[Assert\Length(min: 2, max: 60, minMessage: '2 caractères minimum.', maxMessage: '60 caractères maximum.')]
    private string $label = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: '255 caractères maximum.')]
    private ?string $description = null;

    #[ORM\Column]
    private bool $isSystem = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = trim((string) $label);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = null === $description ? null : trim($description);
        $this->description = '' === $description ? null : $description;

        return $this;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setSystem(bool $isSystem): static
    {
        $this->isSystem = $isSystem;

        return $this;
    }

    /** Le rôle admin a toutes les permissions, sans exception possible. */
    public function isAdmin(): bool
    {
        return User::ROLE_ADMIN === $this->code;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
