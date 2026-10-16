<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Réglages du site (une seule ligne). */
#[ORM\Entity]
#[ORM\Table(name: 'site_setting')]
class SiteSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $headerTitle = 'PROCOM Tools';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $headerSubtitle = 'La bibliothèque d\'outils de PROCOM';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    public function getHeaderTitle(): string
    {
        return $this->headerTitle;
    }

    public function setHeaderTitle(string $headerTitle): static
    {
        $this->headerTitle = $headerTitle;

        return $this;
    }

    public function getHeaderSubtitle(): ?string
    {
        return $this->headerSubtitle;
    }

    public function setHeaderSubtitle(?string $headerSubtitle): static
    {
        $this->headerSubtitle = $headerSubtitle;

        return $this;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;

        return $this;
    }
}
