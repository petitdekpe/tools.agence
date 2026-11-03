<?php

namespace App\Form;

use App\Entity\Materiel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MaterielType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('designation', TextType::class, ['label' => 'Désignation'])
            ->add('category', ChoiceType::class, ['label' => 'Catégorie', 'choices' => array_flip(Materiel::CATEGORIES)])
            ->add('reference', TextType::class, ['label' => 'Référence', 'required' => false, 'help' => 'Numéro de série ou code d\'inventaire. Unique si renseignée.'])
            ->add('etat', ChoiceType::class, ['label' => 'État', 'choices' => array_flip(Materiel::ETATS)])
            ->add('purchasedAt', DateType::class, [
                'label' => 'Date d\'achat',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => ['max' => date('Y-m-d')],
                'invalid_message' => 'Date invalide.',
            ])
            ->add('purchaseValue', NumberType::class, [
                'label' => 'Valeur d\'achat (FCFA)',
                'required' => false,
                'input' => 'string',
                'scale' => 0,
                'html5' => true,
                'attr' => ['min' => 0, 'step' => 1, 'inputmode' => 'numeric'],
                'invalid_message' => 'Montant invalide.',
            ])
            ->add('observation', TextareaType::class, ['label' => 'Observation', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 2000]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Materiel::class]);
    }
}
