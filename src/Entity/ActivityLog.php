<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Une action effectuée (ou tentée) par un utilisateur. Le journal survit à la suppression du compte. */
#[ORM\Entity]
#[ORM\Table(name: 'activity_log')]
#[ORM\Index(columns: ['created_at'])]
#[ORM\Index(columns: ['user_id', 'created_at'])]
#[ORM\Index(columns: ['action'])]
class ActivityLog
{
    public const LABELS = [
        'auth.login' => 'Connexion',
        'auth.login_failed' => 'Échec de connexion',
        'auth.logout' => 'Déconnexion',
        'auth.2fa_enabled' => 'Authentificateur activé',
        'auth.2fa_reset' => 'Authentificateur réinitialisé',
        'tool.open' => 'Ouverture d\'un outil',
        'correction.create' => 'Correction créée',
        'correction.view' => 'Correction consultée',
        'correction.update' => 'Correction modifiée',
        'correction.delete' => 'Correction supprimée',
        'user.create' => 'Utilisateur créé',
        'user.update' => 'Utilisateur modifié',
        'user.toggle' => 'Utilisateur activé/désactivé',
        'user.delete' => 'Utilisateur supprimé',
        'settings.update' => 'Réglages du site modifiés',
    ];

    public const CATEGORIES = [
        'auth' => 'Authentification',
        'tool' => 'Outils',
        'correction' => 'Corrections',
        'user' => 'Gestion des utilisateurs',
        'settings' => 'Réglages',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** E-mail au moment de l'action (conservé même si le compte est supprimé, ou inconnu). */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $userEmail = null;

    #[ORM\Column(length: 40)]
    private string $action = '';

    #[ORM\Column(type: Types::JSON)]
    private array $details = [];

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getUserEmail(): ?string
    {
        return $this->userEmail;
    }

    public function setUserEmail(?string $userEmail): static
    {
        $this->userEmail = $userEmail;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getLabel(): string
    {
        return self::LABELS[$this->action] ?? $this->action;
    }

    public function getCategory(): string
    {
        return strstr($this->action, '.', true) ?: $this->action;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function setDetails(array $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
