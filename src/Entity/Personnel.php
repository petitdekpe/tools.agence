<?php

namespace App\Entity;

use App\Repository\PersonnelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Un membre du personnel. Suppression logique ; le départ se marque par une date de sortie. */
#[ORM\Entity(repositoryClass: PersonnelRepository::class)]
#[ORM\Table(name: 'personnel')]
#[ORM\Index(columns: ['last_name', 'first_name'])]
#[ORM\Index(columns: ['deleted_at'])]
#[UniqueEntity('user', message: 'Ce compte est déjà rattaché à une autre fiche.')]
class Personnel
{
    public const CONTRACT_CDI = 'cdi';
    public const CONTRACT_CDD = 'cdd';

    public const CONTRACTS = [
        self::CONTRACT_CDI => 'CDI',
        self::CONTRACT_CDD => 'CDD',
        'stage' => 'Stage',
        'prestataire' => 'Prestataire',
        'autre' => 'Autre',
    ];

    /** Nombre de jours avant la fin d'un contrat à partir duquel on le signale. */
    public const CONTRACT_WARNING_DAYS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 80)]
    private string $lastName = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
    #[Assert\Length(max: 80)]
    private string $firstName = '';

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $jobTitle = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $department = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'E-mail invalide.')]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $phone = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date d\'entrée est obligatoire.')]
    private ?\DateTimeImmutable $hiredAt = null;

    /** Date de départ ; la personne est considérée comme partie à partir de ce jour. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $leftAt = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['cdi', 'cdd', 'stage', 'prestataire', 'autre'], message: 'Type de contrat invalide.')]
    private string $contractType = self::CONTRACT_CDI;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contractStart = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contractEnd = null;

    /** Compte de l'application rattaché à cette fiche, s'il existe. */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, unique: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 5000, maxMessage: '5 000 caractères maximum.')]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        if ($this->hiredAt && $this->leftAt && $this->leftAt < $this->hiredAt) {
            $context->buildViolation('La date de départ est antérieure à la date d\'entrée.')->atPath('leftAt')->addViolation();
        }
        if ($this->contractStart && $this->contractEnd && $this->contractEnd < $this->contractStart) {
            $context->buildViolation('La fin du contrat est antérieure à son début.')->atPath('contractEnd')->addViolation();
        }
        if (self::CONTRACT_CDD === $this->contractType && !$this->contractEnd) {
            $context->buildViolation('La date de fin est obligatoire pour un CDD.')->atPath('contractEnd')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = trim((string) $lastName);

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = trim((string) $firstName);

        return $this;
    }

    public function getFullName(): string
    {
        return trim($this->firstName.' '.mb_strtoupper($this->lastName));
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(?string $jobTitle): static
    {
        $this->jobTitle = self::blankToNull($jobTitle);

        return $this;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function setDepartment(?string $department): static
    {
        $this->department = self::blankToNull($department);

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $email = self::blankToNull($email);
        $this->email = null === $email ? null : mb_strtolower($email);

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = self::blankToNull($phone);

        return $this;
    }

    public function getHiredAt(): ?\DateTimeImmutable
    {
        return $this->hiredAt;
    }

    public function setHiredAt(?\DateTimeImmutable $hiredAt): static
    {
        $this->hiredAt = $hiredAt;

        return $this;
    }

    public function getLeftAt(): ?\DateTimeImmutable
    {
        return $this->leftAt;
    }

    public function setLeftAt(?\DateTimeImmutable $leftAt): static
    {
        $this->leftAt = $leftAt;

        return $this;
    }

    public function getContractType(): string
    {
        return $this->contractType;
    }

    public function setContractType(?string $contractType): static
    {
        $this->contractType = (string) $contractType;

        return $this;
    }

    public function getContractLabel(): string
    {
        return self::CONTRACTS[$this->contractType] ?? $this->contractType;
    }

    public function getContractStart(): ?\DateTimeImmutable
    {
        return $this->contractStart;
    }

    public function setContractStart(?\DateTimeImmutable $contractStart): static
    {
        $this->contractStart = $contractStart;

        return $this;
    }

    public function getContractEnd(): ?\DateTimeImmutable
    {
        return $this->contractEnd;
    }

    public function setContractEnd(?\DateTimeImmutable $contractEnd): static
    {
        $this->contractEnd = $contractEnd;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = self::blankToNull($notes);

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

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function softDelete(): static
    {
        $this->deletedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Vrai tant que la personne est en poste : pas de date de départ, ou départ à venir. */
    public function isActive(?\DateTimeImmutable $on = null): bool
    {
        $on ??= new \DateTimeImmutable('today');

        return !$this->isDeleted() && (null === $this->leftAt || $this->leftAt > $on);
    }

    /** Nombre de jours avant la fin du contrat (négatif si échu), ou null si pas de fin ou si la personne est partie. */
    public function contractDaysLeft(?\DateTimeImmutable $on = null): ?int
    {
        if (!$this->contractEnd || !$this->isActive($on)) {
            return null;
        }
        $on ??= new \DateTimeImmutable('today');

        return (int) $on->diff($this->contractEnd)->format('%r%a');
    }

    public function isContractEndingSoon(): bool
    {
        $days = $this->contractDaysLeft();

        return null !== $days && $days <= self::CONTRACT_WARNING_DAYS;
    }

    public function snapshot(): array
    {
        return [
            'nom' => $this->lastName,
            'prenom' => $this->firstName,
            'poste' => $this->jobTitle,
            'service' => $this->department,
            'email' => $this->email,
            'telephone' => $this->phone,
            'entree' => $this->hiredAt?->format('d/m/Y'),
            'depart' => $this->leftAt?->format('d/m/Y'),
            'contrat' => $this->getContractLabel(),
            'debut_contrat' => $this->contractStart?->format('d/m/Y'),
            'fin_contrat' => $this->contractEnd?->format('d/m/Y'),
            'compte' => $this->user?->getEmail(),
            'notes' => $this->notes,
        ];
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
