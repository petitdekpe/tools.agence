<?php

namespace App\Service;

use App\Entity\FiscalSetting;
use Doctrine\ORM\EntityManagerInterface;

/** Accès aux paramètres fiscaux ; la ligne est créée avec les valeurs par défaut au premier accès. */
class FiscalSettings
{
    private ?FiscalSetting $cached = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function get(): FiscalSetting
    {
        if ($this->cached) {
            return $this->cached;
        }

        $setting = $this->em->getRepository(FiscalSetting::class)->findOneBy([]);
        if (!$setting) {
            $setting = new FiscalSetting();
            $this->em->persist($setting);
            $this->em->flush();
        }

        return $this->cached = $setting;
    }
}
