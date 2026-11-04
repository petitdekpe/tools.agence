<?php

namespace App\Entity;

use App\Repository\AbsenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Une demande de congé ou une absence d'un membre du personnel, du premier au dernier jour inclus.
 * La demande est « en attente » jusqu'à la décision d'un gestionnaire. Le nombre de jours compte les jours ouvrés
 * (du lundi au vendredi) ; les jours fériés ne sont pas déduits. Suppression logique.
 */
#[ORM\Entity(repositoryClass: AbsenceRepository::class)]
#[ORM\Table(name: 'absence')]
#[ORM\Index(columns: ['date_debut', 'date_fin'])]
#[ORM\Index(columns: ['deleted_at'])]
class Absence
{
    public const TYPES = [
        'conge' => 'Congé annuel',
        'maladie' => 'Maladie',
        'maternite' => 'Maternité / paternité',
        'sans_solde' => 'Congé sans solde',
        'permission' => 'Permission exceptionnelle',
        'autre' => 'Autre',
    ];

    public const EN_ATTENTE = 'en_attente';
    public const APPROUVEE = 'approuvee';
    public const REFUSEE = 'refusee';
    public const ANNULEE = 'annulee';

    public const STATUTS = [
        self::EN_ATTENTE => 'En attente',
        self::APPROUVEE => 'Approuvée',
        self::REFUSEE => 'Refusée',
        self::ANNULEE => 'Annulée',
    ];

    /** Statuts qui occupent le calendrier : une nouvelle absence ne peut pas les chevaucher. */
    public const OCCUPANTS = [self::EN_ATTENTE, self::APPROUVEE];

    /** Durée maximale d'une absence saisie en une fois, en jours calendaires. */
    public const MAX_SPAN_DAYS = 366;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Choisissez la personne concernée.')]
    private ?Personnel $personnel = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['conge', 'maladie', 'maternite', 'sans_solde', 'permission', 'autre'], message: 'Type d\'absence invalide.')]
    private string $type = 'conge';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date de début est obligatoire.')]
    #[Assert\GreaterThanOrEqual('2000-01-01', message: 'Date invalide.')]
    private ?\DateTimeImmutable $dateDebut = null;

    /** Dernier jour d'absence (inclus). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date de fin est obligatoire.')]
    private ?\DateTimeImmutable $dateFin = null;

    /** Jours ouvrés couverts, recalculés à chaque enregistrement. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $jours = 0;

    #[ORM\Column(length: 20)]
    private string $statut = self::EN_ATTENTE;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000, maxMessage: '1 000 caractères maximum.')]
    private ?string $motif = null;

    /** Note du gestionnaire (obligatoire pour un refus). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000, maxMessage: '1 000 caractères maximum.')]
    private ?string $decision = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

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
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    /** Nombre de jours du lundi au vendredi entre deux dates incluses (0 si l'intervalle est à l'envers). */
    public static function countWorkingDays(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($to < $from) {
            return 0;
        }
        $days = 0;
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') < 6) {
                ++$days;
            }
        }

        return $days;
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if (!$this->dateDebut || !$this->dateFin) {
            return;
        }
        if ($this->dateFin < $this->dateDebut) {
            $context->buildViolation('La date de fin est antérieure à la date de début.')->atPath('dateFin')->addViolation();

            return;
        }
        if ($this->dateDebut->diff($this->dateFin)->days >= self::MAX_SPAN_DAYS) {
            $context->buildViolation('Une absence ne peut pas dépasser un an : saisissez-en plusieurs.')->atPath('dateFin')->addViolation();
        }
        if (0 === self::countWorkingDays($this->dateDebut, $this->dateFin)) {
            $context->buildViolation('Cette période ne contient aucun jour ouvré (samedi et dimanche seulement).')->atPath('dateFin')->addViolation();
        }
        if (self::REFUSEE === $this->statut && '' === trim((string) $this->decision)) {
            $context->buildViolation('Indiquez la raison du refus.')->atPath('decision')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPersonnel(): ?Personnel
    {
        return $this->personnel;
    }

    public function setPersonnel(?Personnel $personnel): static
    {
        $this->personnel = $personnel;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
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

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getJours(): int
    {
        return $this->jours;
    }

    /** Recalcule les jours ouvrés d'après les dates. */
    public function recomputeDays(): static
    {
        $this->jours = $this->dateDebut && $this->dateFin ? self::countWorkingDays($this->dateDebut, $this->dateFin) : 0;

        return $this;
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

    public function isPending(): bool
    {
        return self::EN_ATTENTE === $this->statut;
    }

    public function isApproved(): bool
    {
        return self::APPROUVEE === $this->statut;
    }

    /** Peut encore être annulée : en attente ou approuvée, et pas entièrement passée. */
    public function isCancellable(?\DateTimeImmutable $today = null): bool
    {
        $today ??= new \DateTimeImmutable('today');

        return \in_array($this->statut, self::OCCUPANTS, true) && null !== $this->dateFin && $this->dateFin >= $today;
    }

    /** Vrai si le jour donné tombe dans l'absence. */
    public function covers(\DateTimeImmutable $day): bool
    {
        return null !== $this->dateDebut && null !== $this->dateFin && $this->dateDebut <= $day && $day <= $this->dateFin;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): static
    {
        $motif = null === $motif ? null : trim($motif);
        $this->motif = '' === $motif ? null : $motif;

        return $this;
    }

    public function getDecision(): ?string
    {
        return $this->decision;
    }

    public function setDecision(?string $decision): static
    {
        $decision = null === $decision ? null : trim($decision);
        $this->decision = '' === $decision ? null : $decision;

        return $this;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    /** Enregistre une décision (approbation, refus ou annulation) et qui l'a prise. */
    public function decide(string $statut, ?User $by): static
    {
        $this->statut = $statut;
        $this->decidedBy = $by;
        $this->decidedAt = new \DateTimeImmutable();
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

    /** Photo lisible de l'absence pour le journal d'activité. */
    public function snapshot(): array
    {
        return [
            'personne' => $this->personnel?->getFullName(),
            'type' => $this->getTypeLabel(),
            'du' => $this->dateDebut?->format('d/m/Y'),
            'au' => $this->dateFin?->format('d/m/Y'),
            'jours' => $this->jours,
            'statut' => $this->getStatutLabel(),
            'motif' => $this->motif,
            'decision' => $this->decision,
        ];
    }
}
