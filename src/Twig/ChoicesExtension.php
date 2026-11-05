<?php

namespace App\Twig;

use App\Service\Choices;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** {{ choices('absence_type') }} : les valeurs d'une liste (code => libellé) ; {{ choice_label('absence_type', code) }} : le libellé d'une valeur. */
class ChoicesExtension extends AbstractExtension
{
    public function __construct(private readonly Choices $choices)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('choices', $this->choices->labels(...)),
            new TwigFunction('choice_label', $this->choices->label(...)),
        ];
    }
}
