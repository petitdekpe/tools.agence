<?php

namespace App\Entity;

use App\Repository\DocumentRepository;
use App\Service\EncaissementCalculator;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un devis ou une facture émis à un client. Seul le TTC est saisi : le HT et la TVA sont calculés
 * (EncaissementCalculator) au taux en vigueur, figé à l'enregistrement. L'état de règlement d'une facture
 * n'est pas stocké : il se déduit des encaissements qui lui sont rattachés.
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'document')]
#[ORM\Index(columns: ['type', 'date_emission'])]
#[ORM\Index(columns: ['deleted_at'])]
#[UniqueEntity('reference', message: 'Ce numéro est déjà utilisé.')]
class Document
{
    /** Plus grand montant que peut porter une colonne decimal(15,2), en francs entiers. */
    public const MAX_AMOUNT = '9999999999999';

    public const TYPE_FACTURE = 'facture';
    public const TYPE_DEVIS = 'devis';

    public const TYPES = [
        self::TYPE_FACTURE => 'Facture',
        self::TYPE_DEVIS => 'Devis',
    ];

    /** Préfixe du numéro automatique : FA-2026-001, DV-2026-001. */
    public const PREFIXES = [self::TYPE_FACTURE => 'FA', self::TYPE_DEVIS => 'DV'];

    public const BROUILLON = 'brouillon';
    public const EMISE = 'emise';
    public const ANNULEE = 'annulee';
    public const ENVOYE = 'envoye';
    public const ACCEPTE = 'accepte';
    public const REFUSE = 'refuse';

    /** Statuts que l'on choisit, par type de document. */
    public const STATUTS = [
        self::TYPE_FACTURE => [
            self::BROUILLON => 'Brouillon',
            self::EMISE => 'Émise',
            self::ANNULEE => 'Annulée',
        ],
        self::TYPE_DEVIS => [
            self::BROUILLON => 'Brouillon',
            self::ENVOYE => 'Envoyé',
            self::ACCEPTE => 'Accepté',
            self::REFUSE => 'Refusé',
        ],
    ];

    /** Délai de paiement proposé par défaut pour une facture, en jours. */
    public const DEFAULT_DELAY_DAYS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $type = self::TYPE_FACTURE;

    #[ORM\Column(length: 30, unique: true)]
    #[Assert\NotBlank(message: 'Le numéro est obligatoire.')]
    #[Assert\Length(max: 30, maxMessage: '30 caractères maximum.')]
    private string $reference = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Le client est obligatoire.')]
    private ?Client $client = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'L\'objet est obligatoire.')]
    #[Assert\Length(max: 200, maxMessage: '200 caractères maximum.')]
    private string $objet = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date d\'émission est obligatoire.')]
    #[Assert\GreaterThanOrEqual('2000-01-01', message: 'Date invalide.')]
    private ?\DateTimeImmutable $dateEmission = null;

    /** Échéance de paiement (facture) ou date limite de validité (devis). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateLimite = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::BROUILLON;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant TTC est obligatoire.')]
    #[Assert\Positive(message: 'Le montant TTC doit être supérieur à 0.')]
    #[Assert\LessThanOrEqual(self::MAX_AMOUNT, message: 'Montant trop élevé.')]
    private ?string $montantTtc = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $montantHt = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $montantTva = '0.00';

    /** Taux de TVA appliqué, figé à la création (en %). */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $tauxTva = '0.00';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: '2 000 caractères maximum.')]
    private ?string $observation = null;

    /** Le devis dont cette facture est issue. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $sourceDevis = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /** Suppression logique : le document reste en base mais disparaît des listes et des totaux. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $deletedBy = null;

    public function __construct(string $type = self::TYPE_FACTURE)
    {
        $this->type = isset(self::TYPES[$type]) ? $type : self::TYPE_FACTURE;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if (!isset(self::STATUTS[$this->type][$this->statut])) {
            $context->buildViolation('Statut invalide.')->atPath('statut')->addViolation();
        }
        if ($this->dateLimite && $this->dateEmission && $this->dateLimite < $this->dateEmission) {
            $context->buildViolation($this->isFacture() ? 'L\'échéance est antérieure à la date d\'émission.' : 'La date de validité est antérieure à la date d\'émission.')
                ->atPath('dateLimite')->addViolation();
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

    public function isFacture(): bool
    {
        return self::TYPE_FACTURE === $this->type;
    }

    public function isDevis(): bool
    {
        return self::TYPE_DEVIS === $this->type;
    }

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type];
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = strtoupper(trim((string) $reference));

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

    public function getObjet(): string
    {
        return $this->objet;
    }

    public function setObjet(?string $objet): static
    {
        $this->objet = trim((string) $objet);

        return $this;
    }

    public function getDateEmission(): ?\DateTimeImmutable
    {
        return $this->dateEmission;
    }

    public function setDateEmission(?\DateTimeImmutable $dateEmission): static
    {
        $this->dateEmission = $dateEmission;

        return $this;
    }

    public function getDateLimite(): ?\DateTimeImmutable
    {
        return $this->dateLimite;
    }

    public function setDateLimite(?\DateTimeImmutable $dateLimite): static
    {
        $this->dateLimite = $dateLimite;

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
        return self::STATUTS[$this->type][$this->statut] ?? $this->statut;
    }

    /** @return array<string, string> les statuts possibles pour ce type de document */
    public function getStatuts(): array
    {
        return self::STATUTS[$this->type];
    }

    public function getMontantTtc(): ?string
    {
        return $this->montantTtc;
    }

    public function setMontantTtc(?string $montantTtc): static
    {
        $this->montantTtc = $montantTtc;

        return $this;
    }

    public function getMontantHt(): string
    {
        return $this->montantHt;
    }

    public function setMontantHt(string $montantHt): static
    {
        $this->montantHt = $montantHt;

        return $this;
    }

    public function getMontantTva(): string
    {
        return $this->montantTva;
    }

    public function setMontantTva(string $montantTva): static
    {
        $this->montantTva = $montantTva;

        return $this;
    }

    public function getTauxTva(): string
    {
        return $this->tauxTva;
    }

    public function setTauxTva(string $tauxTva): static
    {
        $this->tauxTva = $tauxTva;

        return $this;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(?string $observation): static
    {
        $observation = null === $observation ? null : trim($observation);
        $this->observation = '' === $observation ? null : $observation;

        return $this;
    }

    public function getSourceDevis(): ?Document
    {
        return $this->sourceDevis;
    }

    public function setSourceDevis(?Document $sourceDevis): static
    {
        $this->sourceDevis = $sourceDevis;

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

    /** Reste à encaisser sur une facture, en francs, compte tenu de ce qui est déjà encaissé (jamais négatif). */
    public function balance(int|string|null $paid): int
    {
        return max(0, EncaissementCalculator::toInt($this->montantTtc) - EncaissementCalculator::toInt((string) $paid));
    }

    /**
     * État de règlement d'une facture émise : « soldee », « partielle » ou « impayee ».
     * Les brouillons et les factures annulées n'ont pas d'état de règlement (null).
     */
    public function settlement(int|string|null $paid): ?string
    {
        if (!$this->isFacture() || self::EMISE !== $this->statut) {
            return null;
        }
        $paid = EncaissementCalculator::toInt((string) $paid);

        return match (true) {
            $paid >= EncaissementCalculator::toInt($this->montantTtc) => 'soldee',
            $paid > 0 => 'partielle',
            default => 'impayee',
        };
    }

    /** Facture émise, échéance dépassée et pas entièrement encaissée. */
    public function isOverdue(int|string|null $paid, ?\DateTimeImmutable $today = null): bool
    {
        $today ??= new \DateTimeImmutable('today');

        return 'soldee' !== $this->settlement($paid) && null !== $this->settlement($paid)
            && null !== $this->dateLimite && $this->dateLimite < $today;
    }

    /** Devis envoyé dont la date de validité est dépassée. */
    public function isExpired(?\DateTimeImmutable $today = null): bool
    {
        $today ??= new \DateTimeImmutable('today');

        return $this->isDevis() && self::ENVOYE === $this->statut && null !== $this->dateLimite && $this->dateLimite < $today;
    }

    /** Photo lisible du document pour le journal d'activité. */
    public function snapshot(): array
    {
        $int = EncaissementCalculator::toInt(...);

        return [
            'type' => $this->getTypeLabel(),
            'numero' => $this->reference,
            'client' => $this->client?->getName(),
            'objet' => $this->objet,
            'emission' => $this->dateEmission?->format('d/m/Y'),
            'echeance' => $this->dateLimite?->format('d/m/Y'),
            'statut' => $this->getStatutLabel(),
            'ttc' => $int($this->montantTtc),
            'ht' => $int($this->montantHt),
            'tva' => $int($this->montantTva),
            'taux_tva' => sprintf('%.2f', $this->tauxTva),
            'observation' => $this->observation,
        ];
    }
}
