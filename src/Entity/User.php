<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity('email', message: 'Un compte existe déjà avec cet e-mail.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    /** Seuls les e-mails de ces domaines peuvent avoir un compte. */
    public const ALLOWED_DOMAINS = ['agenceprocom.pro', 'beninbouge.com', 'beninbouge.bj', 'procom.pro'];

    public const ROLE_AGENT = 'ROLE_AGENT';
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_DIRECTION = 'ROLE_DIRECTION';

    public const ROLE_LABELS = [
        self::ROLE_AGENT => 'Agent',
        self::ROLE_ADMIN => 'Admin',
        self::ROLE_DIRECTION => 'Direction',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'L\'e-mail est obligatoire.')]
    #[Assert\Email(message: 'E-mail invalide.')]
    private string $email = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 120)]
    private string $fullName = '';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::ROLE_AGENT, self::ROLE_ADMIN, self::ROLE_DIRECTION])]
    private string $role = self::ROLE_AGENT;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column]
    private bool $active = true;

    /** Secret TOTP (Google Authenticator), chiffré. Null tant que l'authentificateur n'est pas inscrit. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $totpSecret = null;

    /** Dernier code accepté, pour interdire sa réutilisation. */
    #[ORM\Column(length: 6, nullable: true)]
    private ?string $totpLastCode = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $totpLastCodeAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = trim($fullName);

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getRoleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? $this->role;
    }

    public function isAdmin(): bool
    {
        return self::ROLE_ADMIN === $this->role;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public static function isAllowedEmail(string $email): bool
    {
        $domain = mb_strtolower(substr(strrchr($email, '@') ?: '', 1));

        return \in_array($domain, self::ALLOWED_DOMAINS, true);
    }

    #[Assert\Callback]
    public function validateEmailDomain(ExecutionContextInterface $context): void
    {
        if ('' !== $this->email && !self::isAllowedEmail($this->email)) {
            $context->buildViolation('Seuls les e-mails @'.implode(', @', self::ALLOWED_DOMAINS).' sont acceptés.')
                ->atPath('email')->addViolation();
        }
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $totpSecret): static
    {
        $this->totpSecret = $totpSecret;
        $this->totpLastCode = null;
        $this->totpLastCodeAt = null;

        return $this;
    }

    public function hasTwoFactor(): bool
    {
        return null !== $this->totpSecret;
    }

    public function getTotpLastCode(): ?string
    {
        return $this->totpLastCode;
    }

    public function getTotpLastCodeAt(): ?\DateTimeImmutable
    {
        return $this->totpLastCodeAt;
    }

    public function rememberTotpCode(string $code): static
    {
        $this->totpLastCode = $code;
        $this->totpLastCodeAt = new \DateTimeImmutable();

        return $this;
    }

    /** Déconnecte la session si le compte est désactivé ou si son rôle ou mot de passe a changé. */
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->isActive()
            && $user->getId() === $this->id
            && $user->getRole() === $this->role
            && $user->getPassword() === $this->password;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return [$this->role];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }
}
