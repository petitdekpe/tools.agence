<?php

namespace App\Entity;

use App\Repository\DepenseRepository;
use App\Service\Choices;
use App\Service\EncaissementCalculator;
use App\Validator\ChoiceOf;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Une dépense de PROCOM auprès d'un fournisseur, payée ou restant à payer. Seul le TTC est saisi : le HT et la TVA déductible
 * sont calculés (EncaissementCalculator) au taux en vigueur, figé à l'enregistrement. Le décaissement est la date de paiement :
 * tant qu'elle est vide, la dépense est « à payer ». Suppression logique.
 */
#[ORM\Entity(repositoryClass: DepenseRepository::class)]
#[ORM\Table(name: 'depense')]
#[ORM\Index(columns: ['date_depense'])]
#[ORM\Index(columns: ['date_paiement'])]
#[ORM\Index(columns: ['deleted_at'])]
class Depense
{
    /** Plus grand montant que peut porter une colonne decimal(15,2), en francs entiers. */
    public const MAX_AMOUNT = '9999999999999';

    public const CATEGORIES = [
        'loyer' => 'Loyer et charges',
        'salaires' => 'Salaires et honoraires',
        'fournitures' => 'Fournitures et consommables',
        'transport' => 'Transport et déplacements',
        'communication' => 'Communication et publicité',
        'soustraitance' => 'Sous-traitance et prestataires',
        'impots' => 'Impôts et taxes',
        'materiel' => 'Matériel et équipement',
        'abonnements' => 'Abonnements et logiciels',
        'autre' => 'Autre',
    ];

    public const MODES = [
        'especes' => 'Espèces',
        'momo' => 'Mobile Money',
        'cheque' => 'Chèque',
        'virement' => 'Virement',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Date de la facture ou de la dépense. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date est obligatoire.')]
    #[Assert\GreaterThanOrEqual('2000-01-01', message: 'Date invalide.')]
    #[Assert\LessThanOrEqual('today', message: 'La date ne peut pas être dans le futur.')]
    private ?\DateTimeImmutable $dateDepense = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Le fournisseur est obligatoire.')]
    #[Assert\Length(max: 160, maxMessage: '160 caractères maximum.')]
    private string $fournisseur = '';

    #[ORM\Column(length: 20)]
    #[ChoiceOf('depense_categorie', message: 'Catégorie invalide.')]
    private string $categorie = 'autre';

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Le libellé est obligatoire.')]
    #[Assert\Length(max: 160, maxMessage: '160 caractères maximum.')]
    private string $libelle = '';

    /** Numéro de la facture du fournisseur, ou du reçu. */
    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(max: 60, maxMessage: '60 caractères maximum.')]
    private ?string $numeroPiece = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant TTC est obligatoire.')]
    #[Assert\Positive(message: 'Le montant TTC doit être supérieur à 0.')]
    #[Assert\LessThanOrEqual(self::MAX_AMOUNT, message: 'Montant trop élevé.')]
    private ?string $montantTtc = null;

    /** Faux pour un fournisseur non assujetti à la TVA : pas de TVA déductible. */
    #[ORM\Column(options: ['default' => true])]
    private bool $tvaApplicable = true;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $montantHt = '0.00';

    /** TVA déductible. */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $montantTva = '0.00';

    /** Taux de TVA appliqué, figé à la création (en %). */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $tauxTva = '0.00';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $echeance = null;

    /** Date du décaissement ; vide tant que la dépense n'est pas payée. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThanOrEqual('2000-01-01', message: 'Date invalide.')]
    #[Assert\LessThanOrEqual('today', message: 'La date de paiement ne peut pas être dans le futur.')]
    private ?\DateTimeImmutable $datePaiement = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $mode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: '2 000 caractères maximum.')]
    private ?string $observation = null;

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

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if (null !== $this->datePaiement && (null === $this->mode || !Choices::has('depense_mode', $this->mode))) {
            $context->buildViolation('Indiquez le mode de paiement.')->atPath('mode')->addViolation();
        }
        if (null !== $this->mode && !Choices::has('depense_mode', $this->mode)) {
            $context->buildViolation('Mode de paiement invalide.')->atPath('mode')->addViolation();
        }
        if ($this->echeance && $this->dateDepense && $this->echeance < $this->dateDepense) {
            $context->buildViolation('L\'échéance est antérieure à la date de la dépense.')->atPath('echeance')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDateDepense(): ?\DateTimeImmutable
    {
        return $this->dateDepense;
    }

    public function setDateDepense(?\DateTimeImmutable $dateDepense): static
    {
        $this->dateDepense = $dateDepense;

        return $this;
    }

    public function getFournisseur(): string
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?string $fournisseur): static
    {
        $this->fournisseur = trim((string) $fournisseur);

        return $this;
    }

    public function getCategorie(): string
    {
        return $this->categorie;
    }

    public function setCategorie(string $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getCategorieLabel(): string
    {
        return Choices::labelOf('depense_categorie', $this->categorie) ?? $this->categorie;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): static
    {
        $this->libelle = trim((string) $libelle);

        return $this;
    }

    public function getNumeroPiece(): ?string
    {
        return $this->numeroPiece;
    }

    public function setNumeroPiece(?string $numeroPiece): static
    {
        $numeroPiece = null === $numeroPiece ? null : trim($numeroPiece);
        $this->numeroPiece = '' === $numeroPiece ? null : $numeroPiece;

        return $this;
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

    public function isTvaApplicable(): bool
    {
        return $this->tvaApplicable;
    }

    public function setTvaApplicable(bool $tvaApplicable): static
    {
        $this->tvaApplicable = $tvaApplicable;

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

    public function getEcheance(): ?\DateTimeImmutable
    {
        return $this->echeance;
    }

    public function setEcheance(?\DateTimeImmutable $echeance): static
    {
        $this->echeance = $echeance;

        return $this;
    }

    public function getDatePaiement(): ?\DateTimeImmutable
    {
        return $this->datePaiement;
    }

    public function setDatePaiement(?\DateTimeImmutable $datePaiement): static
    {
        $this->datePaiement = $datePaiement;

        return $this;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function setMode(?string $mode): static
    {
        $this->mode = '' === $mode ? null : $mode;

        return $this;
    }

    public function getModeLabel(): ?string
    {
        return null === $this->mode ? null : Choices::labelOf('depense_mode', $this->mode);
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

    public function isPaid(): bool
    {
        return null !== $this->datePaiement;
    }

    /** Pas payée et l'échéance est passée. */
    public function isOverdue(?\DateTimeImmutable $today = null): bool
    {
        $today ??= new \DateTimeImmutable('today');

        return !$this->isPaid() && null !== $this->echeance && $this->echeance < $today;
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

    /** Photo lisible de la dépense pour le journal d'activité. */
    public function snapshot(): array
    {
        $int = EncaissementCalculator::toInt(...);

        return [
            'date' => $this->dateDepense?->format('d/m/Y'),
            'fournisseur' => $this->fournisseur,
            'categorie' => $this->getCategorieLabel(),
            'libelle' => $this->libelle,
            'piece' => $this->numeroPiece,
            'ttc' => $int($this->montantTtc),
            'ht' => $int($this->montantHt),
            'tva_deductible' => $int($this->montantTva),
            'taux_tva' => sprintf('%.2f', $this->tauxTva),
            'echeance' => $this->echeance?->format('d/m/Y'),
            'paye_le' => $this->datePaiement?->format('d/m/Y'),
            'mode' => $this->getModeLabel(),
            'observation' => $this->observation,
        ];
    }
}
