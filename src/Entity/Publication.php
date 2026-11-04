<?php

namespace App\Entity;

use App\Repository\PublicationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une publication planifiée (post, vidéo, article, newsletter…) sur un canal, pour un client ou pour PROCOM.
 * Elle avance de « idée » à « publié » en passant par la rédaction, la relecture et la validation. Suppression logique.
 */
#[ORM\Entity(repositoryClass: PublicationRepository::class)]
#[ORM\Table(name: 'publication')]
#[ORM\Index(columns: ['date_publication'])]
#[ORM\Index(columns: ['deleted_at'])]
class Publication
{
    public const CANAUX = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'linkedin' => 'LinkedIn',
        'x' => 'X (Twitter)',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'whatsapp' => 'WhatsApp',
        'site' => 'Site web / blog',
        'newsletter' => 'Newsletter',
        'autre' => 'Autre',
    ];

    public const FORMATS = [
        'post' => 'Post',
        'carrousel' => 'Carrousel',
        'video' => 'Vidéo',
        'story' => 'Story',
        'article' => 'Article',
        'autre' => 'Autre',
    ];

    public const IDEE = 'idee';
    public const REDACTION = 'redaction';
    public const RELECTURE = 'relecture';
    public const VALIDE = 'valide';
    public const PUBLIE = 'publie';
    public const ANNULE = 'annule';

    /** Dans l'ordre du circuit de production. */
    public const STATUTS = [
        self::IDEE => 'Idée',
        self::REDACTION => 'En rédaction',
        self::RELECTURE => 'En relecture',
        self::VALIDE => 'Validé',
        self::PUBLIE => 'Publié',
        self::ANNULE => 'Annulé',
    ];

    /** Statuts d'une publication pas encore sortie : en retard si sa date est passée. */
    public const EN_COURS = [self::IDEE, self::REDACTION, self::RELECTURE, self::VALIDE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(max: 160, maxMessage: '160 caractères maximum.')]
    private string $titre = '';

    /** Le client concerné ; vide pour une publication de PROCOM elle-même. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Client $client = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['facebook', 'instagram', 'linkedin', 'x', 'tiktok', 'youtube', 'whatsapp', 'site', 'newsletter', 'autre'], message: 'Canal invalide.')]
    private string $canal = 'facebook';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['post', 'carrousel', 'video', 'story', 'article', 'autre'], message: 'Format invalide.')]
    private string $format = 'post';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date de publication est obligatoire.')]
    #[Assert\GreaterThanOrEqual('2000-01-01', message: 'Date invalide.')]
    private ?\DateTimeImmutable $datePublication = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $heure = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::IDEE;

    /** La personne qui rédige ou prépare. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Personnel $responsable = null;

    /** Le texte (légende, script, brouillon d'article…), à faire relire avec le Correcteur média. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 8000, maxMessage: '8 000 caractères maximum.')]
    private ?string $contenu = null;

    /** Lien de la publication une fois en ligne. */
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(requireTld: true, message: 'Lien invalide (commencez par https://).')]
    #[Assert\Length(max: 500)]
    private ?string $lien = null;

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

    public function getId(): ?int
    {
        return $this->id;
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

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getCanal(): string
    {
        return $this->canal;
    }

    public function setCanal(string $canal): static
    {
        $this->canal = $canal;

        return $this;
    }

    public function getCanalLabel(): string
    {
        return self::CANAUX[$this->canal] ?? $this->canal;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function getFormatLabel(): string
    {
        return self::FORMATS[$this->format] ?? $this->format;
    }

    public function getDatePublication(): ?\DateTimeImmutable
    {
        return $this->datePublication;
    }

    public function setDatePublication(?\DateTimeImmutable $datePublication): static
    {
        $this->datePublication = $datePublication;

        return $this;
    }

    public function getHeure(): ?\DateTimeImmutable
    {
        return $this->heure;
    }

    public function setHeure(?\DateTimeImmutable $heure): static
    {
        $this->heure = $heure;

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

    public function getResponsable(): ?Personnel
    {
        return $this->responsable;
    }

    public function setResponsable(?Personnel $responsable): static
    {
        $this->responsable = $responsable;

        return $this;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(?string $contenu): static
    {
        $contenu = null === $contenu ? null : trim($contenu);
        $this->contenu = '' === $contenu ? null : $contenu;

        return $this;
    }

    public function getLien(): ?string
    {
        return $this->lien;
    }

    public function setLien(?string $lien): static
    {
        $lien = null === $lien ? null : trim($lien);
        $this->lien = '' === $lien ? null : $lien;

        return $this;
    }

    /** Pas encore sortie et sa date est passée. */
    public function isLate(?\DateTimeImmutable $today = null): bool
    {
        $today ??= new \DateTimeImmutable('today');

        return \in_array($this->statut, self::EN_COURS, true) && null !== $this->datePublication && $this->datePublication < $today;
    }

    /** Le texte attend une relecture ou une validation. */
    public function isAwaitingReview(): bool
    {
        return self::RELECTURE === $this->statut;
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

    /** Photo lisible de la publication pour le journal d'activité. */
    public function snapshot(): array
    {
        return [
            'titre' => $this->titre,
            'client' => $this->client?->getName(),
            'canal' => $this->getCanalLabel(),
            'format' => $this->getFormatLabel(),
            'date' => $this->datePublication?->format('d/m/Y'),
            'heure' => $this->heure?->format('H:i'),
            'statut' => $this->getStatutLabel(),
            'responsable' => $this->responsable?->getFullName(),
            'lien' => $this->lien,
        ];
    }
}
