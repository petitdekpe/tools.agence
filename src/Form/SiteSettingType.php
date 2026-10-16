<?php

namespace App\Form;

use App\Entity\SiteSetting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class SiteSettingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('headerTitle', TextType::class, ['label' => 'Titre du header'])
            ->add('headerSubtitle', TextType::class, ['label' => 'Sous-titre du header', 'required' => false])
            ->add('logo', FileType::class, [
                'label' => 'Logo (PNG, JPG, SVG ou WebP, 2 Mo max)',
                'mapped' => false,
                'required' => false,
                'constraints' => [new File(
                    maxSize: '2M',
                    mimeTypes: ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'],
                    mimeTypesMessage: 'Format d\'image non supporté.',
                )],
            ])
            ->add('removeLogo', CheckboxType::class, ['label' => 'Supprimer le logo actuel', 'mapped' => false, 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SiteSetting::class]);
    }
}
