<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** La valeur doit être l'une de celles de la liste de choix indiquée (voir App\Service\Choices). Une valeur vide n'est pas vérifiée. */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class ChoiceOf extends Constraint
{
    public function __construct(public string $list, public string $message = 'Valeur invalide.', ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }
}
