<?php

namespace App\Form;

use App\Entity\Personnel;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PersonnelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = static fn (string $label, array $extra = []): array => $extra + [
            'label' => $label,
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'required' => false,
            'invalid_message' => 'Date invalide.',
        ];

        $builder
            ->add('lastName', TextType::class, ['label' => 'Nom'])
            ->add('firstName', TextType::class, ['label' => 'Prénom'])
            ->add('jobTitle', TextType::class, ['label' => 'Poste / fonction', 'required' => false])
            ->add('department', TextType::class, ['label' => 'Service', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'required' => false])
            ->add('phone', TelType::class, ['label' => 'Téléphone', 'required' => false])
            ->add('hiredAt', DateType::class, $date('Date d\'entrée', ['required' => true]))
            ->add('leftAt', DateType::class, $date('Date de départ', ['help' => 'À renseigner quand la personne quitte l\'entreprise. Le matériel qu\'elle détient sera alors signalé.']))
            ->add('contractType', ChoiceType::class, ['label' => 'Type de contrat', 'choices' => array_flip(Personnel::CONTRACTS)])
            ->add('contractStart', DateType::class, $date('Début du contrat'))
            ->add('contractEnd', DateType::class, $date('Fin du contrat', ['help' => 'Obligatoire pour un CDD.']))
            ->add('user', EntityType::class, [
                'label' => 'Compte de l\'application',
                'class' => User::class,
                'required' => false,
                'placeholder' => 'Aucun compte',
                'choice_label' => static fn (User $u): string => $u->getFullName().' ('.$u->getEmail().')',
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('u')->orderBy('u.fullName', 'ASC'),
                'help' => 'Optionnel : rattache cette fiche à un utilisateur de PROCOM Tools.',
            ])
            ->add('notes', TextareaType::class, ['label' => 'Notes', 'required' => false, 'attr' => ['rows' => 4, 'maxlength' => 5000]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Personnel::class]);
    }
}
