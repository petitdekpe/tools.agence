<?php

namespace App\Entity;

use App\Repository\EncaissementRepository;
use App\Service\Choices;
use App\Service\EncaissementCalculator;
use App\Validator\ChoiceOf;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un paiement reçu d'un client. Seuls le TTC, la retenue de TVA et la répartition sont saisis :
 * le reste est calculé par EncaissementCalculator, qui fige aussi les taux appliqués.
 */
#[ORM\Entity(repositoryClass: EncaissementRepository::class)]
#[ORM\Table(name: 'encaissement')]
#[ORM\Index(columns: ['date_encaissement'])]
#[ORM\Index(columns: ['deleted_at'])]
class Encaissement
{
    /** Plus grand montant que peut porter une colonne decimal(15,2), en francs entiers. */
    public const MAX_AMOUNT = '9999999999999';

    public const NATURE_FACTURE = 'facture';
    public const NATURE_AVANCE = 'avance';

    /** Nature du paiement. Le calcul de TVA et d'AIB est le même pour toutes : la TVA est due dès l'encaissement. */
    public const NATURES = [
        self::NATURE_FACTURE => 'Paiement de facture',
        self::NATURE_AVANCE => 'Avance / acompte',
        'solde' => 'Solde',
        'autre' => 'Autre',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date est obligatoire.')]
    #[Assert\GreaterThanOrEqual('2000-01-01', message: 'Date invalide.')]
    #[Assert\LessThanOrEqual('today', message: 'La date ne peut pas être dans le futur.')]
    private ?\DateTimeImmutable $dateEncaissement = null;

    #[ORM\Column(length: 20, options: ['default' => self::NATURE_FACTURE])]
    #[ChoiceOf('encaissement_nature', message: 'Nature invalide.')]
    private string $nature = self::NATURE_FACTURE;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Le client est obligatoire.')]
    private ?Client $client = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    #[Assert\NotBlank(message: 'Le montant TTC est obligatoire.')]
    #[Assert\Positive(message: 'Le montant TTC doit être supérieur à 0.')]
    #[Assert\LessThanOrEqual(self::MAX_AMOUNT, message: 'Montant trop élevé.')]
    private ?string $montantTtc = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $montantHt = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $tvaFacturee = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $tvaPreleveeSource = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $tvaAReverser = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $aibPreleveSource = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $montantNetRecu = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, options: ['default' => '0.00'])]
    #[Assert\NotNull(message: 'Le montant est obligatoire (0 si aucun).')]
    #[Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.')]
    #[Assert\LessThanOrEqual(self::MAX_AMOUNT, message: 'Montant trop élevé.')]
    private ?string $montantEspecesMomo = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, options: ['default' => '0.00'])]
    #[Assert\NotNull(message: 'Le montant est obligatoire (0 si aucun).')]
    #[Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.')]
    #[Assert\LessThanOrEqual(self::MAX_AMOUNT, message: 'Montant trop élevé.')]
    private ?string $montantChequeVirement = '0.00';

    #[ORM\Column]
    private bool $retenueTvaSource = false;

    /** Taux de TVA appliqué, figé à l'enregistrement (en %). */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $tauxTva = '0.00';

    /** Taux d'AIB appliqué, figé à l'enregistrement (en %). */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $tauxAib = '0.00';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: '2 000 caractères maximum.')]
    private ?string $observation = null;

    /** La facture réglée (en tout ou partie) par cet encaissement, si on la connaît. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $facture = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /** Suppression logique : une ligne supprimée reste en base mais disparaît des listes et des totaux. */
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
    public function validateFacture(ExecutionContextInterface $context): void
    {
        if (null === $this->facture) {
            return;
        }
        if (!$this->facture->isFacture()) {
            $context->buildViolation('Seule une facture peut être réglée par un encaissement.')->atPath('facture')->addViolation();
        } elseif ($this->client && $this->facture->getClient() !== $this->client) {
            $context->buildViolation('Cette facture est au nom de '.$this->facture->getClient()?->getName().', pas du client choisi.')->atPath('facture')->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateRepartition(ExecutionContextInterface $context): void
    {
        if (null === $this->montantEspecesMomo || null === $this->montantChequeVirement) {
            return;
        }
        $ecart = $this->getEcart();
        if ($ecart < 0) {
            $context->buildViolation('La répartition dépasse le net reçu de '.number_format(-$ecart, 0, '', ' ').' FCFA.')
                ->atPath('montantChequeVirement')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDateEncaissement(): ?\DateTimeImmutable
    {
        return $this->dateEncaissement;
    }

    public function setDateEncaissement(?\DateTimeImmutable $dateEncaissement): static
    {
        $this->dateEncaissement = $dateEncaissement;

        return $this;
    }

    public function getNature(): string
    {
        return $this->nature;
    }

    public function setNature(string $nature): static
    {
        $this->nature = $nature;

        return $this;
    }

    public function getNatureLabel(): string
    {
        return Choices::labelOf('encaissement_nature', $this->nature) ?? $this->nature;
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

    public function getTvaFacturee(): string
    {
        return $this->tvaFacturee;
    }

    public function setTvaFacturee(string $tvaFacturee): static
    {
        $this->tvaFacturee = $tvaFacturee;

        return $this;
    }

    public function getTvaPreleveeSource(): string
    {
        return $this->tvaPreleveeSource;
    }

    public function setTvaPreleveeSource(string $tvaPreleveeSource): static
    {
        $this->tvaPreleveeSource = $tvaPreleveeSource;

        return $this;
    }

    public function getTvaAReverser(): string
    {
        return $this->tvaAReverser;
    }

    public function setTvaAReverser(string $tvaAReverser): static
    {
        $this->tvaAReverser = $tvaAReverser;

        return $this;
    }

    public function getAibPreleveSource(): string
    {
        return $this->aibPreleveSource;
    }

    public function setAibPreleveSource(string $aibPreleveSource): static
    {
        $this->aibPreleveSource = $aibPreleveSource;

        return $this;
    }

    public function getMontantNetRecu(): string
    {
        return $this->montantNetRecu;
    }

    public function setMontantNetRecu(string $montantNetRecu): static
    {
        $this->montantNetRecu = $montantNetRecu;

        return $this;
    }

    public function getMontantEspecesMomo(): ?string
    {
        return $this->montantEspecesMomo;
    }

    public function setMontantEspecesMomo(?string $montantEspecesMomo): static
    {
        $this->montantEspecesMomo = $montantEspecesMomo;

        return $this;
    }

    public function getMontantChequeVirement(): ?string
    {
        return $this->montantChequeVirement;
    }

    public function setMontantChequeVirement(?string $montantChequeVirement): static
    {
        $this->montantChequeVirement = $montantChequeVirement;

        return $this;
    }

    public function isRetenueTvaSource(): bool
    {
        return $this->retenueTvaSource;
    }

    public function setRetenueTvaSource(bool $retenueTvaSource): static
    {
        $this->retenueTvaSource = $retenueTvaSource;

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

    public function getTauxAib(): string
    {
        return $this->tauxAib;
    }

    public function setTauxAib(string $tauxAib): static
    {
        $this->tauxAib = $tauxAib;

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

    public function getFacture(): ?Document
    {
        return $this->facture;
    }

    public function setFacture(?Document $facture): static
    {
        $this->facture = $facture;

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

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function softDelete(?User $by): static
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->deletedBy = $by;

        return $this;
    }

    /** Net reçu moins ce qui a été réparti : 0 = cohérent, positif = reste à répartir, négatif = dépassement. */
    public function getEcart(): int
    {
        return EncaissementCalculator::toInt($this->montantNetRecu)
            - EncaissementCalculator::toInt($this->montantEspecesMomo)
            - EncaissementCalculator::toInt($this->montantChequeVirement);
    }

    public function isCoherent(): bool
    {
        return 0 === $this->getEcart();
    }

    /** Photo lisible de l'encaissement pour le journal d'activité (montants en francs entiers, comparables entre eux). */
    public function snapshot(): array
    {
        $int = EncaissementCalculator::toInt(...);

        return [
            'date' => $this->dateEncaissement?->format('d/m/Y'),
            'client' => $this->client?->getName(),
            'nature' => $this->getNatureLabel(),
            'facture' => $this->facture?->getReference(),
            'ttc' => $int($this->montantTtc),
            'ht' => $int($this->montantHt),
            'tva_facturee' => $int($this->tvaFacturee),
            'tva_prelevee_source' => $int($this->tvaPreleveeSource),
            'tva_a_reverser' => $int($this->tvaAReverser),
            'aib_preleve_source' => $int($this->aibPreleveSource),
            'net_recu' => $int($this->montantNetRecu),
            'especes_momo' => $int($this->montantEspecesMomo),
            'cheque_virement' => $int($this->montantChequeVirement),
            'retenue_tva_source' => $this->retenueTvaSource,
            'taux_tva' => sprintf('%.2f', $this->tauxTva),
            'taux_aib' => sprintf('%.2f', $this->tauxAib),
            'observation' => $this->observation,
        ];
    }
}
