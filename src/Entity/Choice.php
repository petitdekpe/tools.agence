<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une valeur d'une liste de choix gérée depuis l'administration (catégories de matériel, canaux, priorités...).
 * Les listes et leurs valeurs d'origine sont décrites dans App\Service\Choices ; tant qu'une liste n'a jamais été
 * modifiée, elle n'a aucune ligne ici et ses valeurs d'origine s'appliquent.
 *
 * Les autres tables stockent le code, pas l'identifiant : le code est tiré du libellé à la création, puis ne change plus.
 * Renommer une valeur ne change donc rien aux données déjà saisies.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_choice')]
#[ORM\UniqueConstraint(name: 'uniq_choice_list_code', columns: ['list_key', 'code'])]
class Choice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $listKey = '';

    #[ORM\Column(length: 40)]
    private string $code = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Le libellé est obligatoire.')]
    #[Assert\Length(min: 2, max: 80, minMessage: '2 caractères minimum.', maxMessage: '80 caractères maximum.')]
    private string $label = '';

    #[ORM\Column]
    private int $position = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getListKey(): string
    {
        return $this->listKey;
    }

    public function setListKey(string $listKey): static
    {
        $this->listKey = $listKey;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);

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
}
