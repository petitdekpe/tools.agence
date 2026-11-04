<?php

namespace App\Twig;

use App\Entity\SiteSetting;
use App\Service\SiteSettings;
use App\Service\ToolCatalog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SiteExtension extends AbstractExtension
{
    public function __construct(private readonly SiteSettings $settings, private readonly ToolCatalog $tools)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_settings', fn (): SiteSetting => $this->settings->get()),
            new TwigFunction('nav_tools', fn (): array => $this->tools->granted()),
            new TwigFunction('nav_groups', fn (): array => $this->tools->groups()),
        ];
    }
}
