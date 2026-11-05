<?php

namespace App\Entity;

use App\Repository\LinkToolRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un outil « lien » : une tuile de la page d'accueil et une entrée du menu qui ouvrent une adresse externe.
 * Il se gère depuis les réglages du site. Les rôles qui le voient sont conservés par leur code (le code d'un rôle ne change jamais) ;
 * l'admin voit tous les liens actifs.
 */
#[ORM\Entity(repositoryClass: LinkToolRepository::class)]
#[ORM\Table(name: 'link_tool')]
#[ORM\Index(columns: ['position'])]
class LinkTool
{
    /** Icônes proposées : nom de l'icône de templates/_icons.html.twig => libellé. */
    public const ICONS = [
        'link' => 'Lien',
        'handshake' => 'Poignée de main',
        'pen' => 'Stylo',
        'cash' => 'Argent',
        'users' => 'Équipe',
        'invoice' => 'Facture',
        'calendar' => 'Calendrier',
        'megaphone' => 'Mégaphone',
        'wallet' => 'Portefeuille',
        'kanban' => 'Tableau',
        'pin' => 'Lieu',
        'laptop' => 'Ordinateur',
        'chart' => 'Graphique',
        'list' => 'Liste',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Le nom de l\'outil est obligatoire.')]
    #[Assert\Length(max: 120, maxMessage: '120 caractères maximum.')]
    private string $title = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: '255 caractères maximum.')]
    private ?string $description = null;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank(message: 'L\'adresse du lien est obligatoire.')]
    #[Assert\Length(max: 500, maxMessage: '500 caractères maximum.')]
    #[Assert\Url(message: 'Adresse invalide : elle doit commencer par http:// ou https://.', protocols: ['http', 'https'], requireTld: false)]
    private string $url = '';

    #[ORM\Column(length: 20)]
    private string $category = 'autres';

    #[ORM\Column(length: 30)]
    private string $icon = 'link';

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30, maxMessage: '30 caractères maximum.')]
    private ?string $badge = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $active = true;

    /** @var list<string> codes des rôles qui voient ce lien (l'admin le voit toujours) */
    #[ORM\Column(type: Types::JSON)]
    private array $roleCodes = [];

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = trim((string) $title);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = null === $description ? null : trim($description);
        $this->description = '' === $description ? null : $description;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = trim((string) $url);

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getBadge(): ?string
    {
        return $this->badge;
    }

    public function setBadge(?string $badge): static
    {
        $badge = null === $badge ? null : trim($badge);
        $this->badge = '' === $badge ? null : $badge;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /** @return list<string> */
    public function getRoleCodes(): array
    {
        return $this->roleCodes;
    }

    /** @param array<string> $roleCodes */
    public function setRoleCodes(array $roleCodes): static
    {
        $this->roleCodes = array_values(array_unique(array_filter($roleCodes, 'is_string')));

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
