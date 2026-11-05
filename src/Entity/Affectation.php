<?php

namespace App\Entity;

use App\Service\Choices;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Une remise de matériel à un membre du personnel. Ouverte tant qu'il n'y a pas de date de restitution. */
#[ORM\Entity]
#[ORM\Table(name: 'affectation')]
#[ORM\Index(columns: ['returned_at'])]
class Affectation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'affectations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Materiel $materiel;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Personnel $personnel;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $assignedAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $returnedAt = null;

    /** Observation à la remise. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** Observation à la restitution. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $returnNote = null;

    /** État constaté à la restitution (clé de Materiel::ETATS). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $returnEtat = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $returnedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Materiel $materiel, Personnel $personnel, \DateTimeImmutable $assignedAt)
    {
        $this->materiel = $materiel;
        $this->personnel = $personnel;
        $this->assignedAt = $assignedAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMateriel(): Materiel
    {
        return $this->materiel;
    }

    public function getPersonnel(): Personnel
    {
        return $this->personnel;
    }

    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function getReturnedAt(): ?\DateTimeImmutable
    {
        return $this->returnedAt;
    }

    public function isOpen(): bool
    {
        return null === $this->returnedAt;
    }

    public function close(\DateTimeImmutable $returnedAt, ?string $etat, ?string $note, ?User $by): static
    {
        $this->returnedAt = $returnedAt;
        $this->returnEtat = $etat;
        $this->returnNote = $note;
        $this->returnedBy = $by;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $note = null === $note ? null : trim($note);
        $this->note = '' === $note ? null : $note;

        return $this;
    }

    public function getReturnNote(): ?string
    {
        return $this->returnNote;
    }

    public function getReturnEtat(): ?string
    {
        return $this->returnEtat;
    }

    public function getReturnEtatLabel(): ?string
    {
        return null === $this->returnEtat ? null : Choices::labelOf('materiel_etat', $this->returnEtat);
    }

    public function getAssignedBy(): ?User
    {
        return $this->assignedBy;
    }

    public function setAssignedBy(?User $assignedBy): static
    {
        $this->assignedBy = $assignedBy;

        return $this;
    }

    public function getReturnedBy(): ?User
    {
        return $this->returnedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
