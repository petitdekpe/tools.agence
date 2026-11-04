<?php

namespace App\Form;

use App\Entity\Absence;
use App\Entity\Personnel;
use App\Repository\AbsenceRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie d'une absence. Les personnes proposées sont fournies par le contrôleur (chacun ne demande que pour soi,
 * sauf les gestionnaires). Les jours ouvrés sont recalculés côté serveur, et une période qui en recoupe une autre
 * de la même personne est refusée.
 */
class AbsenceType extends AbstractType
{
    public function __construct(private readonly AbsenceRepository $absences)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('personnel', EntityType::class, [
                'label' => 'Personne concernée',
                'class' => Personnel::class,
                'choices' => $options['personnel_choices'],
                'choice_label' => static fn (Personnel $p): string => $p->getFullName().($p->getDepartment() ? ' · '.$p->getDepartment() : ''),
                'placeholder' => 1 === \count($options['personnel_choices']) ? false : 'Choisir une personne',
            ])
            ->add('type', ChoiceType::class, ['label' => 'Type d\'absence', 'choices' => array_flip(Absence::TYPES)])
            ->add('dateDebut', DateType::class, [
                'label' => 'Premier jour d\'absence',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'invalid_message' => 'Date invalide.',
            ])
            ->add('dateFin', DateType::class, [
                'label' => 'Dernier jour d\'absence',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'invalid_message' => 'Date invalide.',
                'help' => 'Inclus. Le nombre de jours compte du lundi au vendredi ; les jours fériés ne sont pas déduits.',
            ])
            ->add('motif', TextareaType::class, ['label' => 'Motif', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 1000]]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $event->getData()->recomputeDays();
        }, 10);

        // Après la validation : une absence ne peut pas en chevaucher une autre (en attente ou approuvée) de la même personne.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            /** @var Absence $absence */
            $absence = $event->getData();
            if ($form->isSynchronized() && 0 === \count($form->getErrors(true)) && ($other = $this->absences->findOverlapping($absence))) {
                $form->get('dateDebut')->addError(new FormError(sprintf(
                    'Cette période chevauche une absence déjà enregistrée pour %s, du %s au %s (%s).',
                    $other->getPersonnel()?->getFullName(),
                    $other->getDateDebut()?->format('d/m/Y'),
                    $other->getDateFin()?->format('d/m/Y'),
                    mb_strtolower($other->getStatutLabel()),
                )));
            }
        }, -10);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Absence::class, 'personnel_choices' => []]);
        $resolver->setAllowedTypes('personnel_choices', 'array');
    }
}
