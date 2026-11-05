<?php

namespace App\Form;

use App\Entity\LinkTool;
use App\Repository\RoleRepository;
use App\Service\ToolCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LinkToolType extends AbstractType
{
    public function __construct(private readonly RoleRepository $roles)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $roles = [];
        foreach ($this->roles->findAllOrdered() as $role) {
            // L'admin voit tous les liens : inutile de le proposer.
            if (!$role->isAdmin()) {
                $roles[$role->getLabel()] = $role->getCode();
            }
        }

        $builder
            ->add('title', TextType::class, ['label' => 'Nom de l\'outil', 'attr' => ['maxlength' => 120, 'placeholder' => 'Ex. : Drive partagé']])
            ->add('url', UrlType::class, [
                'label' => 'Adresse du lien',
                'default_protocol' => null,
                'attr' => ['maxlength' => 500, 'placeholder' => 'https://...'],
                'help' => 'Le lien s\'ouvre dans un nouvel onglet.',
            ])
            ->add('description', TextType::class, ['label' => 'Description', 'required' => false, 'attr' => ['maxlength' => 255], 'help' => 'Facultative : affichée sur la tuile de la page d\'accueil.'])
            ->add('category', ChoiceType::class, ['label' => 'Catégorie du menu', 'choices' => array_flip(ToolCatalog::CATEGORIES)])
            ->add('icon', ChoiceType::class, ['label' => 'Icône', 'choices' => array_flip(LinkTool::ICONS)])
            ->add('badge', TextType::class, ['label' => 'Étiquette', 'required' => false, 'attr' => ['maxlength' => 30, 'placeholder' => 'Lien'], 'help' => 'Petit texte au-dessus du nom sur la tuile. « Lien » par défaut.'])
            ->add('roleCodes', ChoiceType::class, [
                'label' => 'Visible par',
                'choices' => $roles,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'L\'administrateur voit toujours les liens actifs. Sans rôle coché, seul l\'administrateur y a accès.',
            ])
            ->add('active', CheckboxType::class, ['label' => 'Lien actif (affiché dans le menu et sur l\'accueil)', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LinkTool::class]);
    }
}
