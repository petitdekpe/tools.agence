<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Paramètres fiscaux (une seule ligne). Les taux sont en pourcentage, par exemple 18.00. */
#[ORM\Entity]
#[ORM\Table(name: 'fiscal_setting')]
class FiscalSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\NotBlank(message: 'Le taux de TVA est obligatoire.')]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'Le taux doit être compris entre 0 et 100 %.')]
    private string $tauxTva = '18.00';

    /** AIB appliqué aux clients qui ont un IFU. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\NotBlank(message: 'Le taux d\'AIB est obligatoire.')]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'Le taux doit être compris entre 0 et 100 %.')]
    private string $tauxAibAvecIfu = '1.00';

    /** AIB majoré appliqué aux clients sans IFU. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\NotBlank(message: 'Le taux d\'AIB est obligatoire.')]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'Le taux doit être compris entre 0 et 100 %.')]
    private string $tauxAibSansIfu = '5.00';

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTauxTva(): string
    {
        return $this->tauxTva;
    }

    public function setTauxTva(?string $tauxTva): static
    {
        $this->tauxTva = (string) $tauxTva;

        return $this;
    }

    public function getTauxAibAvecIfu(): string
    {
        return $this->tauxAibAvecIfu;
    }

    public function setTauxAibAvecIfu(?string $tauxAibAvecIfu): static
    {
        $this->tauxAibAvecIfu = (string) $tauxAibAvecIfu;

        return $this;
    }

    public function getTauxAibSansIfu(): string
    {
        return $this->tauxAibSansIfu;
    }

    public function setTauxAibSansIfu(?string $tauxAibSansIfu): static
    {
        $this->tauxAibSansIfu = (string) $tauxAibSansIfu;

        return $this;
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

    /** Taux d'AIB à appliquer à ce client selon qu'il a ou non un IFU. */
    public function aibRateFor(?Client $client): string
    {
        return $client?->hasIfu() ? $this->tauxAibAvecIfu : $this->tauxAibSansIfu;
    }
}
