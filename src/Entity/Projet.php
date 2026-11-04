<?php

namespace App\Entity;

use App\Repository\ProjetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un projet de PROCOM, rattaché ou non à un client. Il se déplace sur un tableau (À lancer, En cours, En pause, Terminé)
 * et porte ses propres tâches, présentées elles aussi en colonnes. Suppression logique.
 */
#[ORM\Entity(repositoryClass: ProjetRepository::class)]
#[ORM\Table(name: 'projet')]
#[ORM\Index(columns: ['statut', 'position'])]
#[ORM\Index(columns: ['deleted_at'])]
class Projet
{
    public const A_LANCER = 'a_lancer';
    public const EN_COURS = 'en_cours';
    public const EN_PAUSE = 'en_pause';
    public const TERMINE = 'termine';
    public const ANNULE = 'annule';

    public const STATUTS = [
        self::A_LANCER => 'À lancer',
        self::EN_COURS => 'En cours',
        self::EN_PAUSE => 'En pause',
        self::TERMINE => 'Terminé',
        self::ANNULE => 'Annulé',
    ];

    /** Colonnes du tableau des projets : un projet annulé n'y figure pas. */
    public const COLONNES = [self::A_LANCER, self::EN_COURS, self::EN_PAUSE, self::TERMINE];

    /** Statuts d'un projet encore ouvert : en retard si son échéance est passée. */
    public const OUVERTS = [self::A_LANCER, self::EN_COURS, self::EN_PAUSE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Le nom du projet est obligatoire.')]
    #[Assert\Length(max: 160, maxMessage: '160 caractères maximum.')]
    private string $nom = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 4000, maxMessage: '4 000 caractères maximum.')]
    private ?string $description = null;

    /** Le client concerné ; vide pour un projet interne. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Client $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Personnel $responsable = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $echeance = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::A_LANCER;

    /** Rang dans sa colonne du tableau (0 en haut). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $position = 0;

    /** Date à laquelle le projet est passé à « terminé » ou « annulé ». */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<int, Tache> */
    #[ORM\OneToMany(targetEntity: Tache::class, mappedBy: 'projet')]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $taches;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $deletedBy = null;

    public function __construct()
    {
        $this->taches = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        if ($this->dateDebut && $this->echeance && $this->echeance < $this->dateDebut) {
            $context->buildViolation('L\'échéance est antérieure à la date de début.')->atPath('echeance')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = trim((string) $nom);

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

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getResponsable(): ?Personnel
    {
        return $this->responsable;
    }

    public function setResponsable(?Personnel $responsable): static
    {
        $this->responsable = $responsable;

        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

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

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getStatutLabel(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    /** Change de statut ; la date de clôture suit les passages à « terminé » ou « annulé ». */
    public function transition(string $statut): static
    {
        $this->statut = $statut;
        $this->closedAt = $this->isOpen() ? null : ($this->closedAt ?? new \DateTimeImmutable());
        $this->touch();

        return $this;
    }

    /** Pour le formulaire : le statut choisi passe par transition() au moment de l'enregistrement. */
    public function setStatut(string $statut): static
    {
        return $this->transition($statut);
    }

    public function isOpen(): bool
    {
        return \in_array($this->statut, self::OUVERTS, true);
    }

    public function isLate(?\DateTimeImmutable $today = null): bool
    {
        $today ??= new \DateTimeImmutable('today');

        return $this->isOpen() && null !== $this->echeance && $this->echeance < $today;
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

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    /** @return Collection<int, Tache> */
    public function getTaches(): Collection
    {
        return $this->taches;
    }

    /** Avancement : tâches terminées sur le total, en pourcentage entier (0 sans tâche). */
    public function getProgress(): int
    {
        $total = $this->taches->count();
        if (0 === $total) {
            return 0;
        }
        $done = $this->taches->filter(static fn (Tache $t): bool => Tache::TERMINE === $t->getStatut())->count();

        return (int) round($done / $total * 100);
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

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function softDelete(?User $by): static
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->deletedBy = $by;

        return $this;
    }

    /** Photo lisible du projet pour le journal d'activité. */
    public function snapshot(): array
    {
        return [
            'nom' => $this->nom,
            'client' => $this->client?->getName(),
            'responsable' => $this->responsable?->getFullName(),
            'debut' => $this->dateDebut?->format('d/m/Y'),
            'echeance' => $this->echeance?->format('d/m/Y'),
            'statut' => $this->getStatutLabel(),
            'description' => $this->description,
        ];
    }
}
