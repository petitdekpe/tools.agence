<?php

namespace App\Entity;

use App\Repository\TacheRepository;
use App\Service\Choices;
use App\Validator\ChoiceOf;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Une carte du tableau d'un projet : elle passe de « à faire » à « terminé » en changeant de colonne. */
#[ORM\Entity(repositoryClass: TacheRepository::class)]
#[ORM\Table(name: 'tache')]
#[ORM\Index(columns: ['statut', 'position'])]
class Tache
{
    public const A_FAIRE = 'a_faire';
    public const EN_COURS = 'en_cours';
    public const EN_REVUE = 'en_revue';
    public const TERMINE = 'termine';

    /** Les colonnes du tableau d'un projet, de gauche à droite. */
    public const STATUTS = [
        self::A_FAIRE => 'À faire',
        self::EN_COURS => 'En cours',
        self::EN_REVUE => 'En revue',
        self::TERMINE => 'Terminé',
    ];

    public const PRIORITES = ['basse' => 'Basse', 'normale' => 'Normale', 'haute' => 'Haute'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'taches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Projet $projet = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(max: 160, maxMessage: '160 caractères maximum.')]
    private string $titre = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 4000, maxMessage: '4 000 caractères maximum.')]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::A_FAIRE;

    /** Rang dans sa colonne (0 en haut). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $position = 0;

    #[ORM\Column(length: 10)]
    #[ChoiceOf('tache_priorite', message: 'Priorité invalide.')]
    private string $priorite = 'normale';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Personnel $assignee = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $echeance = null;

    /** Date à laquelle la carte est passée dans la colonne « terminé ». */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProjet(): ?Projet
    {
        return $this->projet;
    }

    public function setProjet(?Projet $projet): static
    {
        $this->projet = $projet;

        return $this;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = trim((string) $titre);

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

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getStatutLabel(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    /** Change de colonne ; la date de clôture suit les passages dans « terminé ». */
    public function transition(string $statut): static
    {
        $this->statut = $statut;
        $this->closedAt = self::TERMINE === $statut ? ($this->closedAt ?? new \DateTimeImmutable()) : null;
        $this->touch();

        return $this;
    }

    /** Pour le formulaire : le statut choisi passe par transition() au moment de l'enregistrement. */
    public function setStatut(string $statut): static
    {
        return $this->transition($statut);
    }

    public function isDone(): bool
    {
        return self::TERMINE === $this->statut;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getPriorite(): string
    {
        return $this->priorite;
    }

    public function setPriorite(string $priorite): static
    {
        $this->priorite = $priorite;

        return $this;
    }

    public function getAssignee(): ?Personnel
    {
        return $this->assignee;
    }

    public function setAssignee(?Personnel $assignee): static
    {
        $this->assignee = $assignee;

        return $this;
    }

    public function getEcheance(): ?\DateTimeImmutable
    {
        return $this->echeance;
    }

    public function setEcheance(?\DateTimeImmutable $echeance): static
    {
        $this->echeance = $echeance;

        return $this;
    }

    public function isLate(?\DateTimeImmutable $today = null): bool
    {
        $today ??= new \DateTimeImmutable('today');

        return !$this->isDone() && null !== $this->echeance && $this->echeance < $today;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /** Photo lisible de la tâche pour le journal d'activité. */
    public function snapshot(): array
    {
        return [
            'projet' => $this->projet?->getNom(),
            'titre' => $this->titre,
            'statut' => $this->getStatutLabel(),
            'priorite' => Choices::labelOf('tache_priorite', $this->priorite) ?? $this->priorite,
            'assignee' => $this->assignee?->getFullName(),
            'echeance' => $this->echeance?->format('d/m/Y'),
            'description' => $this->description,
        ];
    }
}
