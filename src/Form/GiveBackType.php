<?php

namespace App\Form;

use App\Entity\Materiel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/** Restitution d'un matériel. Les données sont un tableau : returnedAt, etat (optionnel), note. */
class GiveBackType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('returnedAt', DateType::class, [
                'label' => 'Date de restitution',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['max' => date('Y-m-d')],
                'constraints' => [new NotNull(message: 'La date est obligatoire.')],
                'invalid_message' => 'Date invalide.',
            ])
            ->add('etat', ChoiceType::class, [
                'label' => 'État constaté au retour',
                'choices' => array_flip(Materiel::ETATS),
                'required' => false,
                'placeholder' => 'Inchangé',
            ])
            ->add('note', TextareaType::class, ['label' => 'Observation', 'required' => false, 'attr' => ['rows' => 2, 'maxlength' => 1000]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
