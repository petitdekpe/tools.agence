<?php

namespace App\Form;

use App\Entity\Role;
use App\Entity\User;
use App\Repository\RoleRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Création d'un compte à partir d'une fiche du personnel : l'adresse qui recevra le mot de passe, et le rôle. */
class AccountInvitationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-mail du compte',
                'help' => 'Le mot de passe temporaire y sera envoyé. Il sert aussi à récupérer le compte si l\'authentificateur n\'est plus disponible.',
                'constraints' => [
                    new NotBlank(message: 'L\'e-mail est obligatoire.'),
                    new Email(message: 'E-mail invalide.'),
                    new Callback(static function (?string $value, ExecutionContextInterface $context): void {
                        if ($value && !User::isAllowedEmail($value)) {
                            $context->buildViolation('Seuls les e-mails @'.implode(', @', User::ALLOWED_DOMAINS).' sont acceptés.')->addViolation();
                        }
                    }),
                ],
            ])
            ->add('role', EntityType::class, [
                'label' => 'Rôle',
                'class' => Role::class,
                'query_builder' => static fn (RoleRepository $roles) => $roles->orderedQuery(),
                'choice_label' => 'label',
                'choice_attr' => static fn (Role $role): array => $role->getDescription() ? ['title' => $role->getDescription()] : [],
                'placeholder' => 'Choisir un rôle',
                'invalid_message' => 'Ce rôle n\'existe pas.',
                'constraints' => [new NotNull(message: 'Choisissez un rôle.')],
                'help' => 'Le rôle décide des outils accessibles. Il se règle dans Administration > Rôles.',
            ]);
    }
}
