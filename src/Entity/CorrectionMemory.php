<?php

namespace App\Entity;

use App\Repository\CorrectionMemoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une entrée de la mémoire du Correcteur média, créée par un clic « Retenir » ou « Ignorer » sur une remarque.
 * Personnelle (dossier précis, ou tous les dossiers de l'auteur quand il est vide) ou partagée : une règle de l'équipe, promue par un administrateur.
 */
#[ORM\Entity(repositoryClass: CorrectionMemoryRepository::class)]
#[ORM\Table(name: 'correction_memory')]
#[ORM\Index(columns: ['user_id', 'dossier'])]
#[ORM\Index(columns: ['shared'])]
class CorrectionMemory
{
    /** Un choix validé : la correction ou la graphie à appliquer. */
    public const KIND_CHOIX = 'choix';
    /** Une remarque à ne plus signaler. */
    public const KIND_EXCLUSION = 'exclusion';

    public const CONTENT_MAX = 300;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: Correction::DOSSIER_MAX, nullable: true)]
    private ?string $dossier = null;

    #[ORM\Column(length: 12)]
    private string $kind = self::KIND_CHOIX;

    /** Le texte d'origine cité par la remarque : sert à retrouver les entrées pertinentes pour un texte. */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $anchor = null;

    #[ORM\Column(length: self::CONTENT_MAX)]
    private string $content = '';

    #[ORM\Column]
    private bool $shared = false;

    /** La relecture d'où vient l'entrée ; elle lui survit si la relecture est supprimée. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Correction $source = null;

    #[ORM\Column(nullable: true)]
    private ?int $sourceRemark = null;

    #[ORM\Column]
    private int $useCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getDossier(): ?string
    {
        return $this->dossier;
    }

    public function setDossier(?string $dossier): static
    {
        $this->dossier = Correction::normalizeDossier($dossier);

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = self::KIND_EXCLUSION === $kind ? self::KIND_EXCLUSION : self::KIND_CHOIX;

        return $this;
    }

    public function getAnchor(): ?string
    {
        return $this->anchor;
    }

    public function setAnchor(?string $anchor): static
    {
        $anchor = null === $anchor ? '' : trim($anchor);
        $this->anchor = '' === $anchor ? null : mb_substr($anchor, 0, 200);

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $content = trim($content);
        $this->content = mb_strlen($content) > self::CONTENT_MAX ? rtrim(mb_substr($content, 0, self::CONTENT_MAX - 1)).'…' : $content;

        return $this;
    }

    public function isShared(): bool
    {
        return $this->shared;
    }

    public function setShared(bool $shared): static
    {
        $this->shared = $shared;

        return $this;
    }

    public function getSource(): ?Correction
    {
        return $this->source;
    }

    public function setSource(?Correction $source, ?int $remark = null): static
    {
        $this->source = $source;
        $this->sourceRemark = $remark;

        return $this;
    }

    public function getSourceRemark(): ?int
    {
        return $this->sourceRemark;
    }

    public function getUseCount(): int
    {
        return $this->useCount;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): static
    {
        ++$this->useCount;
        $this->lastUsedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
