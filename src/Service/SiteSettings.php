<?php

namespace App\Service;

use App\Entity\SiteSetting;
use Doctrine\ORM\EntityManagerInterface;

class SiteSettings
{
    private ?SiteSetting $cached = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function get(): SiteSetting
    {
        if ($this->cached) {
            return $this->cached;
        }

        $setting = $this->em->getRepository(SiteSetting::class)->findOneBy([]);
        if (!$setting) {
            $setting = new SiteSetting();
            $this->em->persist($setting);
            $this->em->flush();
        }

        return $this->cached = $setting;
    }
}
