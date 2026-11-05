<?php

namespace App\Entity;

use App\Repository\MaterielRepository;
use App\Service\Choices;
use App\Validator\ChoiceOf;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un équipement de l'inventaire. Son détenteur est l'affectation ouverte (sans date de restitution) ;
 * toutes les affectations passées restent consultables. Suppression logique.
 */
#[ORM\Entity(repositoryClass: MaterielRepository::class)]
#[ORM\Table(name: 'materiel')]
#[ORM\Index(columns: ['deleted_at'])]
#[UniqueEntity(fields: ['reference'], message: 'Cette référence est déjà utilisée par un autre matériel.', repositoryMethod: 'findActiveByReference', ignoreNull: true)]
class Materiel
{
    public const ETAT_REFORME = 'reforme';

    public const CATEGORIES = [
        'informatique' => 'Informatique',
        'telephonie' => 'Téléphonie',
        'audiovisuel' => 'Audio / vidéo',
        'mobilier' => 'Mobilier',
        'vehicule' => 'Véhicule',
        'outillage' => 'Outillage',
        'autre' => 'Autre',
    ];

    public const ARTICLES = [
        'ordinateur_portable' => 'Ordinateur portable',
        'ecran' => 'Écran',
        'clavier' => 'Clavier',
        'souris' => 'Souris',
        'casque' => 'Casque audio',
        'cable_hdmi' => 'Câble HDMI',
        'telephone' => 'Téléphone',
        'appareil_photo' => 'Appareil photo',
    ];

    public const ETATS = [
        'neuf' => 'Neuf',
        'bon' => 'Bon état',
        'use' => 'Usé',
        'panne' => 'En panne',
        self::ETAT_REFORME => 'Réformé',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'La désignation est obligatoire.')]
    #[Assert\Length(max: 160)]
    private string $designation = '';

    #[ORM\Column(length: 20)]
    #[ChoiceOf('materiel_category', message: 'Catégorie invalide.')]
    private string $category = 'informatique';

    /** Article de la liste de référence (code d'une valeur de « materiel_article »), facultatif. */
    #[ORM\Column(length: 40, nullable: true)]
    #[ChoiceOf('materiel_article', message: 'Article de référence invalide.')]
    private ?string $article = null;

    /** Nombre d'unités du lot : le lot entier a un seul détenteur. */
    #[ORM\Column(options: ['default' => 1])]
    #[Assert\NotNull(message: 'La quantité est obligatoire.')]
    #[Assert\Positive(message: 'La quantité doit être d\'au moins 1.')]
    #[Assert\LessThanOrEqual(99999, message: 'Quantité trop élevée.')]
    private int $quantity = 1;

    /** Numéro de série ou code d'inventaire. */
    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $reference = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\LessThanOrEqual('today', message: 'La date d\'achat ne peut pas être dans le futur.')]
    private ?\DateTimeImmutable $purchasedAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'La valeur ne peut pas être négative.')]
    #[Assert\LessThanOrEqual('9999999999999', message: 'Montant trop élevé.')]
    private ?string $purchaseValue = null;

    #[ORM\Column(length: 20)]
    #[ChoiceOf('materiel_etat', message: 'État invalide.')]
    private string $etat = 'bon';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000, maxMessage: '2 000 caractères maximum.')]
    private ?string $observation = null;

    /** @var Collection<int, Affectation> */
    #[ORM\OneToMany(targetEntity: Affectation::class, mappedBy: 'materiel')]
    #[ORM\OrderBy(['assignedAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $affectations;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->affectations = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDesignation(): string
    {
        return $this->designation;
    }

    public function setDesignation(?string $designation): static
    {
        $this->designation = trim((string) $designation);

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = (string) $category;

        return $this;
    }

    public function getCategoryLabel(): string
    {
        return Choices::labelOf('materiel_category', $this->category) ?? $this->category;
    }

    public function getArticle(): ?string
    {
        return $this->article;
    }

    public function setArticle(?string $article): static
    {
        $this->article = '' === $article ? null : $article;

        return $this;
    }

    public function getArticleLabel(): ?string
    {
        return Choices::labelOf('materiel_article', $this->article);
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(?int $quantity): static
    {
        $this->quantity = (int) $quantity;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $reference = null === $reference ? null : trim($reference);
        $this->reference = '' === $reference ? null : $reference;

        return $this;
    }

    public function getPurchasedAt(): ?\DateTimeImmutable
    {
        return $this->purchasedAt;
    }

    public function setPurchasedAt(?\DateTimeImmutable $purchasedAt): static
    {
        $this->purchasedAt = $purchasedAt;

        return $this;
    }

    public function getPurchaseValue(): ?string
    {
        return $this->purchaseValue;
    }

    public function setPurchaseValue(?string $purchaseValue): static
    {
        $this->purchaseValue = $purchaseValue;

        return $this;
    }

    public function getEtat(): string
    {
        return $this->etat;
    }

    public function setEtat(?string $etat): static
    {
        $this->etat = (string) $etat;

        return $this;
    }

    public function getEtatLabel(): string
    {
        return Choices::labelOf('materiel_etat', $this->etat) ?? $this->etat;
    }

    public function isReformed(): bool
    {
        return self::ETAT_REFORME === $this->etat;
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

    /** @return Collection<int, Affectation> de la plus récente à la plus ancienne */
    public function getAffectations(): Collection
    {
        return $this->affectations;
    }

    public function getCurrentAffectation(): ?Affectation
    {
        foreach ($this->affectations as $affectation) {
            if ($affectation->isOpen()) {
                return $affectation;
            }
        }

        return null;
    }

    public function getHolder(): ?Personnel
    {
        return $this->getCurrentAffectation()?->getPersonnel();
    }

    public function isAvailable(): bool
    {
        return !$this->isDeleted() && !$this->isReformed() && null === $this->getCurrentAffectation();
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

    public function snapshot(): array
    {
        return [
            'designation' => $this->designation,
            'categorie' => $this->getCategoryLabel(),
            'article' => $this->getArticleLabel(),
            'quantite' => $this->quantity,
            'reference' => $this->reference,
            'date_achat' => $this->purchasedAt?->format('d/m/Y'),
            'valeur_achat' => null === $this->purchaseValue ? null : (int) $this->purchaseValue,
            'etat' => $this->getEtatLabel(),
            'observation' => $this->observation,
        ];
    }
}
