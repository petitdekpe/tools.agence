<?php

namespace App\Form;

use App\Entity\Role;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Nom et description d'un rôle. Les permissions, groupées par outil, sont lues dans la requête par le contrôleur
 * (champ « permissions[] ») : leur catalogue n'est pas une entité.
 */
class RoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => 'Nom du rôle', 'attr' => ['maxlength' => 60, 'placeholder' => 'Ex. : Chef de projet']])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 2, 'maxlength' => 255],
                'help' => 'Facultative : à quoi sert ce rôle.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Role::class]);
    }
}
