<?php

namespace App\Validator;

use App\Service\Choices;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ChoiceOfValidator extends ConstraintValidator
{
    public function __construct(private readonly Choices $choices)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ChoiceOf) {
            throw new UnexpectedTypeException($constraint, ChoiceOf::class);
        }
        if (null === $value || '' === $value) {
            return;
        }
        if (!\is_string($value) || !$this->choices->exists($constraint->list, $value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
