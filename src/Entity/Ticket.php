<?php

namespace App\Entity;

use App\Repository\TicketRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un signalement de panne sur un équipement de l'inventaire, ou une demande de matériel (nouveau ou de remplacement).
 * Le suivi va de « ouvert » à « résolu » ou « rejeté ». Suppression logique.
 */
#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'ticket')]
#[ORM\Index(columns: ['type', 'statut'])]
#[ORM\Index(columns: ['deleted_at'])]
#[UniqueEntity('reference', message: 'Ce numéro est déjà utilisé.')]
class Ticket
{
    public const TYPE_PANNE = 'panne';
    public const TYPE_DEMANDE = 'demande';

    public const TYPES = [
        self::TYPE_PANNE => 'Panne',
        self::TYPE_DEMANDE => 'Demande',
    ];

    /** Préfixe du numéro automatique : PN-2026-001, DM-2026-001. */
    public const PREFIXES = [self::TYPE_PANNE => 'PN', self::TYPE_DEMANDE => 'DM'];

    public const OUVERT = 'ouvert';
    public const EN_COURS = 'en_cours';
    public const RESOLU = 'resolu';
    public const REJETE = 'rejete';

    public const STATUTS = [
        self::OUVERT => 'Ouvert',
        self::EN_COURS => 'En cours',
        self::RESOLU => 'Résolu',
        self::REJETE => 'Rejeté',
    ];

    /** Statuts d'un ticket encore à traiter. */
    public const ACTIFS = [self::OUVERT, self::EN_COURS];

    public const PRIORITES = [
        'basse' => 'Basse',
        'normale' => 'Normale',
        'haute' => 'Haute',
        'urgente' => 'Urgente',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $type = self::TYPE_PANNE;

    #[ORM\Column(length: 30, unique: true)]
    private string $reference = '';

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(max: 160, maxMessage: '160 caractères maximum.')]
    private string $titre = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: '2 000 caractères maximum.')]
    private ?string $description = null;

    /** L'équipement en panne (obligatoire pour une panne) ou à remplacer (demande). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Materiel $materiel = null;

    /** Catégorie du matériel demandé (demande uniquement). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $categorie = null;

    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: ['basse', 'normale', 'haute', 'urgente'], message: 'Priorité invalide.')]
    private string $priorite = 'normale';

    #[ORM\Column(length: 20)]
    private string $statut = self::OUVERT;

    /** Ce qui a été fait, ou la raison d'un rejet. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: '2 000 caractères maximum.')]
    private ?string $resolution = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $handledBy = null;

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

    public function __construct(string $type = self::TYPE_PANNE)
    {
        $this->type = isset(self::TYPES[$type]) ? $type : self::TYPE_PANNE;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if ($this->isPanne() && null === $this->materiel) {
            $context->buildViolation('Choisissez l\'équipement en panne.')->atPath('materiel')->addViolation();
        }
        if ($this->isPanne() && $this->materiel?->isReformed()) {
            $context->buildViolation('Cet équipement est réformé.')->atPath('materiel')->addViolation();
        }
        if ($this->isDemande() && null !== $this->categorie && !isset(Materiel::CATEGORIES[$this->categorie])) {
            $context->buildViolation('Catégorie invalide.')->atPath('categorie')->addViolation();
        }
        if (self::REJETE === $this->statut && '' === trim((string) $this->resolution)) {
            $context->buildViolation('Indiquez la raison du rejet.')->atPath('resolution')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isPanne(): bool
    {
        return self::TYPE_PANNE === $this->type;
    }

    public function isDemande(): bool
    {
        return self::TYPE_DEMANDE === $this->type;
    }

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type];
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = strtoupper(trim($reference));

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

    public function getMateriel(): ?Materiel
    {
        return $this->materiel;
    }

    public function setMateriel(?Materiel $materiel): static
    {
        $this->materiel = $materiel;

        return $this;
    }

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function setCategorie(?string $categorie): static
    {
        $this->categorie = '' === $categorie ? null : $categorie;

        return $this;
    }

    public function getCategorieLabel(): ?string
    {
        return null === $this->categorie ? null : (Materiel::CATEGORIES[$this->categorie] ?? $this->categorie);
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

    public function getPrioriteLabel(): string
    {
        return self::PRIORITES[$this->priorite] ?? $this->priorite;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getStatutLabel(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    /** Encore à traiter : ouvert ou en cours. */
    public function isActive(): bool
    {
        return \in_array($this->statut, self::ACTIFS, true);
    }

    public function getResolution(): ?string
    {
        return $this->resolution;
    }

    public function setResolution(?string $resolution): static
    {
        $resolution = null === $resolution ? null : trim($resolution);
        $this->resolution = '' === $resolution ? null : $resolution;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getHandledBy(): ?User
    {
        return $this->handledBy;
    }

    /** Change le statut ; la date de clôture suit les passages à « résolu » ou « rejeté ». */
    public function transition(string $statut, ?User $by): static
    {
        $this->statut = $statut;
        $this->handledBy = $by;
        $this->closedAt = $this->isActive() ? null : new \DateTimeImmutable();
        $this->touch();

        return $this;
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

    /** Photo lisible du ticket pour le journal d'activité. */
    public function snapshot(): array
    {
        return [
            'type' => $this->getTypeLabel(),
            'numero' => $this->reference,
            'titre' => $this->titre,
            'materiel' => $this->materiel?->getDesignation(),
            'categorie' => $this->getCategorieLabel(),
            'priorite' => $this->getPrioriteLabel(),
            'statut' => $this->getStatutLabel(),
            'description' => $this->description,
            'resolution' => $this->resolution,
        ];
    }
}
