<?php

namespace App\Entity;

use App\Repository\PersonnelDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un fichier du dossier personnel : pièce d'identité, CV, diplôme le plus récent ou photo (4 au maximum).
 * Le fichier est rangé hors du dossier public (voir PersonnelFiles) et ne se télécharge que par son contrôleur.
 */
#[ORM\Entity(repositoryClass: PersonnelDocumentRepository::class)]
#[ORM\Table(name: 'personnel_document')]
#[ORM\Index(columns: ['personnel_id', 'kind'])]
class PersonnelDocument
{
    public const IDENTITY = 'identity';
    public const CV = 'cv';
    public const DIPLOMA = 'diploma';
    public const PHOTO = 'photo';

    public const KINDS = [
        self::IDENTITY => 'Pièce d\'identité',
        self::CV => 'CV',
        self::DIPLOMA => 'Diplôme le plus récent',
        self::PHOTO => 'Photo',
    ];

    /** Nombre maximal de photos par personne. */
    public const MAX_PHOTOS = 4;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Personnel $personnel;

    #[ORM\Column(length: 12)]
    private string $kind;

    /** Nom du fichier sur le disque (aléatoire). */
    #[ORM\Column(length: 80)]
    private string $path;

    /** Nom d'origine, pour le téléchargement. */
    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $size;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Personnel $personnel, string $kind, string $path, string $originalName, string $mimeType, int $size)
    {
        $this->personnel = $personnel;
        $this->kind = $kind;
        $this->path = $path;
        $this->originalName = mb_substr($originalName, 0, 255);
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPersonnel(): Personnel
    {
        return $this->personnel;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getKindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
