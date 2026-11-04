<?php

namespace App\Form;

use App\Entity\Personnel;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\RoleRepository;
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
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
            ->add('notes', TextareaType::class, ['label' => 'Notes', 'required' => false, 'attr' => ['rows' => 4, 'maxlength' => 5000]]);

        if ($options['is_new']) {
            // Une nouvelle fiche crée son compte : l'e-mail sert d'identifiant et reçoit le mot de passe temporaire.
            $builder->add('email', EmailType::class, [
                'label' => 'E-mail',
                'help' => 'Un compte PROCOM Tools est créé avec cette adresse et le mot de passe temporaire y est envoyé.',
                'constraints' => [
                    new NotBlank(message: 'L\'e-mail est obligatoire : il sert à créer le compte.'),
                    new Callback(static function (?string $value, ExecutionContextInterface $context): void {
                        if ($value && !User::isAllowedEmail($value)) {
                            $context->buildViolation('Seuls les e-mails @'.implode(', @', User::ALLOWED_DOMAINS).' sont acceptés.')->addViolation();
                        }
                    }),
                ],
            ]);
            if ($options['choose_role']) {
                $builder->add('role', EntityType::class, [
                    'label' => 'Rôle du compte',
                    'class' => Role::class,
                    'mapped' => false,
                    'query_builder' => static fn (RoleRepository $roles) => $roles->orderedQuery(),
                    'choice_label' => 'label',
                    'choice_attr' => static fn (Role $role): array => $role->getDescription() ? ['title' => $role->getDescription()] : [],
                    'placeholder' => 'Choisir un rôle',
                    'invalid_message' => 'Ce rôle n\'existe pas.',
                    'constraints' => [new NotNull(message: 'Choisissez un rôle.')],
                    'help' => 'Le rôle décide des outils accessibles. Il se règle dans Administration > Rôles.',
                ]);
            }
        } else {
            $builder->add('user', EntityType::class, [
                'label' => 'Compte de l\'application',
                'class' => User::class,
                'required' => false,
                'placeholder' => 'Aucun compte',
                'choice_label' => static fn (User $u): string => $u->getFullName().' ('.$u->getEmail().')',
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('u')->orderBy('u.fullName', 'ASC'),
                'help' => 'Optionnel : rattache cette fiche à un utilisateur de PROCOM Tools.',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Personnel::class, 'is_new' => false, 'choose_role' => false]);
    }
}
