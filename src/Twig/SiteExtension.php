<?php

namespace App\Twig;

use App\Entity\SiteSetting;
use App\Service\SiteSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SiteExtension extends AbstractExtension
{
    public function __construct(private readonly SiteSettings $settings)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('site_settings', fn (): SiteSetting => $this->settings->get())];
    }
}
