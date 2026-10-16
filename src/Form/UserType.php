<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = $options['is_new'];

        $builder
            ->add('fullName', TextType::class, ['label' => 'Nom complet'])
            ->add('email', EmailType::class, ['label' => 'E-mail'])
            ->add('role', ChoiceType::class, [
                'label' => 'Rôle',
                'choices' => array_flip(User::ROLE_LABELS),
                'help' => 'Agent : utilise les outils. Admin : gère utilisateurs et réglages. Direction : consulte le tableau de bord et les utilisateurs.',
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => $isNew ? 'Mot de passe initial' : 'Nouveau mot de passe',
                'mapped' => false,
                'required' => $isNew,
                'attr' => ['autocomplete' => 'new-password'],
                'help' => $isNew ? '8 caractères minimum. Sert uniquement à la première connexion, où l\'utilisateur activera Google Authenticator.' : 'Laisser vide pour conserver le mot de passe actuel.',
                'constraints' => array_filter([
                    $isNew ? new NotBlank(message: 'Le mot de passe est obligatoire.') : null,
                    new Length(min: 8, minMessage: '8 caractères minimum.'),
                ]),
            ])
            ->add('active', CheckboxType::class, ['label' => 'Compte actif (peut se connecter)', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class, 'is_new' => false]);
    }
}
