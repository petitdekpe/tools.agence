<?php

namespace App\Form;

use App\Entity\Materiel;
use App\Entity\Ticket;
use App\Service\Numbering;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Signalement d'une panne ou demande de matériel. Le type est fixé à la création. Le numéro est attribué
 * automatiquement ; la case « marquer en panne » (panne uniquement) n'est pas enregistrée dans le ticket :
 * le contrôleur s'en sert pour passer l'équipement à l'état « en panne ».
 */
class TicketType extends AbstractType
{
    public function __construct(private readonly Numbering $numbering)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Ticket $ticket */
        $ticket = $builder->getData();
        $isPanne = $ticket->isPanne();

        $builder
            ->add('titre', TextType::class, [
                'label' => $isPanne ? 'Résumé de la panne' : 'Que faut-il ?',
                'attr' => ['maxlength' => 160, 'placeholder' => $isPanne ? 'Ex. L\'écran ne s\'allume plus' : 'Ex. Un second écran 27 pouces'],
            ])
            ->add('materiel', EntityType::class, [
                'label' => $isPanne ? 'Équipement en panne' : 'Équipement à remplacer (facultatif)',
                'class' => Materiel::class,
                'required' => $isPanne,
                'placeholder' => $isPanne ? 'Choisir l\'équipement' : 'Aucun, c\'est un nouvel équipement',
                'choice_label' => static fn (Materiel $m): string => $m->getDesignation().($m->getReference() ? ' ('.$m->getReference().')' : ''),
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('m')
                    ->andWhere('m.deletedAt IS NULL')->andWhere('m.etat <> :reforme')->setParameter('reforme', Materiel::ETAT_REFORME)
                    ->orderBy('m.designation', 'ASC'),
            ]);

        if (!$isPanne) {
            $builder->add('categorie', ChoiceType::class, [
                'label' => 'Catégorie',
                'required' => false,
                'placeholder' => 'Non précisée',
                'choices' => array_flip(Materiel::CATEGORIES),
            ]);
        }

        $builder
            ->add('priorite', ChoiceType::class, ['label' => 'Priorité', 'choices' => array_flip(Ticket::PRIORITES)])
            ->add('description', TextareaType::class, [
                'label' => $isPanne ? 'Ce qui s\'est passé' : 'Pourquoi et pour qui ?',
                'required' => false,
                'attr' => ['rows' => 5, 'maxlength' => 2000],
            ]);

        if ($isPanne) {
            $builder->add('marquerEnPanne', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'data' => true,
                'label' => 'Passer l\'équipement à l\'état « en panne » dans l\'inventaire',
            ]);
        }

        // Le numéro est attribué avant la validation, à la création seulement.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            /** @var Ticket $ticket */
            $ticket = $event->getData();
            if (null === $ticket->getId() && '' === $ticket->getReference()) {
                $ticket->setReference($this->numbering->next(Ticket::class, Ticket::PREFIXES[$ticket->getType()], new \DateTimeImmutable('today')));
            }
        }, 10);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Ticket::class]);
    }
}
