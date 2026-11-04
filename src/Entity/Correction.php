<?php

namespace App\Entity;

use App\Repository\CorrectionRepository;
use App\Service\MediaReview;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une relecture du Correcteur média : le texte soumis et la réponse de l'IA.
 * Les relectures d'un utilisateur forment son fil unique ; le dossier regroupe celles d'un même sujet (partenaire, événement, campagne).
 */
#[ORM\Entity(repositoryClass: CorrectionRepository::class)]
#[ORM\Table(name: 'correction')]
#[ORM\Index(columns: ['user_id', 'updated_at'])]
#[ORM\Index(columns: ['user_id', 'dossier'])]
class Correction
{
    public const DOSSIER_MAX = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 160)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $inputText = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $resultText = '';

    #[ORM\Column(length: self::DOSSIER_MAX, nullable: true)]
    private ?string $dossier = null;

    /** La relecture dont celle-ci est une version révisée. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Correction $previous = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $model = null;

    /** Consommation de l'appel à l'API : tokens d'entrée hors cache, de sortie, lus dans le cache, écrits dans le cache. */
    #[ORM\Column(nullable: true)]
    private ?int $inputTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $outputTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $cacheReadTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $cacheWriteTokens = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = mb_substr(trim($title), 0, 160);

        return $this;
    }

    public function getInputText(): string
    {
        return $this->inputText;
    }

    public function setInputText(string $inputText): static
    {
        $this->inputText = $inputText;

        return $this;
    }

    public function getResultText(): string
    {
        return $this->resultText;
    }

    public function setResultText(string $resultText): static
    {
        $this->resultText = $resultText;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDossier(): ?string
    {
        return $this->dossier;
    }

    public function setDossier(?string $dossier): static
    {
        $this->dossier = self::normalizeDossier($dossier);

        return $this;
    }

    /** Espaces réduits, 120 caractères au plus ; vide = pas de dossier. */
    public static function normalizeDossier(?string $name): ?string
    {
        $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $name) ?? ''), 0, self::DOSSIER_MAX);

        return '' === $name ? null : $name;
    }

    public function getPrevious(): ?Correction
    {
        return $this->previous;
    }

    public function setPrevious(?Correction $previous): static
    {
        $this->previous = $previous;

        return $this;
    }

    public function recordUsage(MediaReview $review): static
    {
        $this->model = $review->model;
        $this->inputTokens = $review->inputTokens;
        $this->outputTokens = $review->outputTokens;
        $this->cacheReadTokens = $review->cacheReadTokens;
        $this->cacheWriteTokens = $review->cacheWriteTokens;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function getCacheReadTokens(): ?int
    {
        return $this->cacheReadTokens;
    }

    public function getCacheWriteTokens(): ?int
    {
        return $this->cacheWriteTokens;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
