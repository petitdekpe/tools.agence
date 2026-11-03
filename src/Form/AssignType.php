<?php

namespace App\Form;

use App\Entity\Personnel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/** Remise d'un matériel. Les données sont un tableau : personnel, assignedAt, note. */
class AssignType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('personnel', ChoiceType::class, [
                'label' => 'Remis à',
                'choices' => $options['staff'],
                'choice_label' => static fn (Personnel $p): string => $p->getFullName().($p->getJobTitle() ? ' · '.$p->getJobTitle() : ''),
                'choice_value' => static fn (?Personnel $p): ?string => null === $p ? null : (string) $p->getId(),
                'placeholder' => 'Choisir une personne',
                'constraints' => [new NotNull(message: 'Choisissez la personne.')],
            ])
            ->add('assignedAt', DateType::class, [
                'label' => 'Date de remise',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['max' => date('Y-m-d')],
                'constraints' => [new NotNull(message: 'La date est obligatoire.')],
                'invalid_message' => 'Date invalide.',
            ])
            ->add('note', TextareaType::class, ['label' => 'Observation', 'required' => false, 'attr' => ['rows' => 2, 'maxlength' => 1000]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('staff');
        $resolver->setAllowedTypes('staff', 'array');
        $resolver->setDefaults(['data_class' => null]);
    }
}
