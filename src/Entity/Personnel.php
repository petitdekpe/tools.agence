<?php

namespace App\Entity;

use App\Repository\PersonnelRepository;
use App\Service\Choices;
use App\Service\Zodiac;
use App\Validator\ChoiceOf;
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
    #[ChoiceOf('personnel_contract', message: 'Type de contrat invalide.')]
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

    // --- Profil personnel : complété par la personne elle-même depuis sa page profil ---

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\LessThan('today', message: 'La date de naissance doit être passée.')]
    #[Assert\GreaterThan('1900-01-01', message: 'Date invalide.')]
    private ?\DateTimeImmutable $birthDate = null;

    /** Début de l'aventure avec PROCOM : distinct des dates de contrat, qui ne racontent pas toujours le même parcours. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\LessThanOrEqual('today', message: 'Cette date ne peut pas être dans le futur.')]
    #[Assert\GreaterThan('1990-01-01', message: 'Date invalide.')]
    private ?\DateTimeImmutable $adventureStartedAt = null;

    /** Indication de lieu (quartier, ville, repère). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: '255 caractères maximum.')]
    private ?string $residence = null;

    /** Lien Google Maps (ou autre carte) du lieu de résidence. */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500, maxMessage: '500 caractères maximum.')]
    #[Assert\Url(message: 'Lien invalide : il doit commencer par http:// ou https://.', protocols: ['http', 'https'], requireTld: true)]
    private ?string $residenceMapUrl = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'E-mail invalide.')]
    #[Assert\Length(max: 180)]
    private ?string $personalEmail = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120, maxMessage: '120 caractères maximum.')]
    private ?string $emergency1Name = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $emergency1Phone = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120, maxMessage: '120 caractères maximum.')]
    private ?string $emergency2Name = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $emergency2Phone = null;

    /** @var array<string, string> réseau (SOCIALS) => adresse du profil ou @identifiant */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $socials = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** Réseaux sociaux proposés : clé => [libellé, adresse de base pour un simple @identifiant]. */
    public const SOCIALS = [
        'facebook' => ['Facebook', 'https://www.facebook.com/'],
        'instagram' => ['Instagram', 'https://www.instagram.com/'],
        'twitter' => ['X (Twitter)', 'https://x.com/'],
        'tiktok' => ['TikTok', 'https://www.tiktok.com/@'],
        'linkedin' => ['LinkedIn', 'https://www.linkedin.com/in/'],
    ];

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateProfile(ExecutionContextInterface $context): void
    {
        foreach ($this->socials ?? [] as $key => $value) {
            if (mb_strlen((string) $value) > 255) {
                $context->buildViolation('255 caractères maximum.')->atPath('socials['.$key.']')->addViolation();
            } elseif (preg_match('#^[a-z][a-z0-9+.-]*:#i', (string) $value) && !preg_match('#^https?://#i', (string) $value)) {
                $context->buildViolation('Adresse invalide : utilisez un lien http(s):// ou votre @identifiant.')->atPath('socials['.$key.']')->addViolation();
            }
        }
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
        return Choices::labelOf('personnel_contract', $this->contractType) ?? $this->contractType;
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

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate;

        return $this;
    }

    /** Signe du zodiaque, déduit de la date de naissance (jamais saisi). @return array{key: string, label: string, symbol: string}|null */
    public function getZodiac(): ?array
    {
        return $this->birthDate ? Zodiac::of($this->birthDate) : null;
    }

    public function getAdventureStartedAt(): ?\DateTimeImmutable
    {
        return $this->adventureStartedAt;
    }

    public function setAdventureStartedAt(?\DateTimeImmutable $adventureStartedAt): static
    {
        $this->adventureStartedAt = $adventureStartedAt;

        return $this;
    }

    public function getResidence(): ?string
    {
        return $this->residence;
    }

    public function setResidence(?string $residence): static
    {
        $this->residence = self::blankToNull($residence);

        return $this;
    }

    public function getResidenceMapUrl(): ?string
    {
        return $this->residenceMapUrl;
    }

    public function setResidenceMapUrl(?string $residenceMapUrl): static
    {
        $this->residenceMapUrl = self::blankToNull($residenceMapUrl);

        return $this;
    }

    /** Le lien Maps saisi ; à défaut, une recherche Google Maps sur l'indication de lieu. */
    public function getMapsLink(): ?string
    {
        if ($this->residenceMapUrl) {
            return $this->residenceMapUrl;
        }

        return $this->residence ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($this->residence) : null;
    }

    public function getPersonalEmail(): ?string
    {
        return $this->personalEmail;
    }

    public function setPersonalEmail(?string $personalEmail): static
    {
        $personalEmail = self::blankToNull($personalEmail);
        $this->personalEmail = null === $personalEmail ? null : mb_strtolower($personalEmail);

        return $this;
    }

    public function getEmergency1Name(): ?string
    {
        return $this->emergency1Name;
    }

    public function setEmergency1Name(?string $name): static
    {
        $this->emergency1Name = self::blankToNull($name);

        return $this;
    }

    public function getEmergency1Phone(): ?string
    {
        return $this->emergency1Phone;
    }

    public function setEmergency1Phone(?string $phone): static
    {
        $this->emergency1Phone = self::blankToNull($phone);

        return $this;
    }

    public function getEmergency2Name(): ?string
    {
        return $this->emergency2Name;
    }

    public function setEmergency2Name(?string $name): static
    {
        $this->emergency2Name = self::blankToNull($name);

        return $this;
    }

    public function getEmergency2Phone(): ?string
    {
        return $this->emergency2Phone;
    }

    public function setEmergency2Phone(?string $phone): static
    {
        $this->emergency2Phone = self::blankToNull($phone);

        return $this;
    }

    /** @return list<array{name: string, phone: ?string}> les personnes à contacter renseignées, dans l'ordre */
    public function getEmergencyContacts(): array
    {
        $contacts = [];
        foreach ([[$this->emergency1Name, $this->emergency1Phone], [$this->emergency2Name, $this->emergency2Phone]] as [$name, $phone]) {
            if ($name || $phone) {
                $contacts[] = ['name' => $name ?? '', 'phone' => $phone];
            }
        }

        return $contacts;
    }

    /** @return array<string, string> */
    public function getSocials(): array
    {
        return $this->socials ?? [];
    }

    /** @param array<string, mixed>|null $socials seuls les réseaux connus et non vides sont gardés */
    public function setSocials(?array $socials): static
    {
        $clean = [];
        foreach (self::SOCIALS as $key => $_) {
            $value = trim((string) ($socials[$key] ?? ''));
            if ('' !== $value) {
                $clean[$key] = $value;
            }
        }
        $this->socials = $clean ?: null;

        return $this;
    }

    /** @return list<array{key: string, label: string, value: string, url: string}> les réseaux renseignés, avec une adresse cliquable */
    public function getSocialLinks(): array
    {
        $links = [];
        foreach (self::SOCIALS as $key => [$label, $base]) {
            $value = $this->getSocials()[$key] ?? null;
            if (null === $value) {
                continue;
            }
            $url = preg_match('#^https?://#i', $value) ? $value : $base.ltrim($value, '@/');
            $links[] = ['key' => $key, 'label' => $label, 'value' => $value, 'url' => $url];
        }

        return $links;
    }

    /** Les champs du profil qui peuvent changer, pour savoir lesquels la personne a modifiés (sans en recopier le contenu). */
    public function profileSnapshot(): array
    {
        return [
            'telephone' => $this->phone,
            'naissance' => $this->birthDate?->format('Y-m-d'),
            'debut_aventure' => $this->adventureStartedAt?->format('Y-m-d'),
            'residence' => $this->residence,
            'lien_maps' => $this->residenceMapUrl,
            'email_personnel' => $this->personalEmail,
            'urgence_1' => [$this->emergency1Name, $this->emergency1Phone],
            'urgence_2' => [$this->emergency2Name, $this->emergency2Phone],
            'reseaux' => $this->getSocials(),
        ];
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
