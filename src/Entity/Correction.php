<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Une relecture du Correcteur média : le texte soumis et la réponse de l'IA. */
#[ORM\Entity]
#[ORM\Table(name: 'correction')]
#[ORM\Index(columns: ['user_id', 'updated_at'])]
class Correction
{
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
