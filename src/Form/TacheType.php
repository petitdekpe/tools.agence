<?php

namespace App\Form;

use App\Entity\Personnel;
use App\Entity\Tache;
use App\Service\Choices;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Saisie d'une carte du tableau d'un projet. */
class TacheType extends AbstractType
{
    public function __construct(private readonly Choices $choices)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, ['label' => 'Titre', 'attr' => ['maxlength' => 160]])
            ->add('statut', ChoiceType::class, ['label' => 'Colonne', 'choices' => array_flip(Tache::STATUTS)])
            ->add('assignee', EntityType::class, [
                'label' => 'Assignée à',
                'class' => Personnel::class,
                'required' => false,
                'placeholder' => 'Personne',
                'choice_label' => static fn (Personnel $p): string => $p->getFullName(),
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('p')
                    ->andWhere('p.deletedAt IS NULL')->andWhere('p.leftAt IS NULL OR p.leftAt > :today')
                    ->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable')
                    ->orderBy('p.lastName', 'ASC')->addOrderBy('p.firstName', 'ASC'),
            ])
            ->add('priorite', ChoiceType::class, ['label' => 'Priorité', 'choices' => array_flip($this->choices->labels('tache_priorite'))])
            ->add('echeance', DateType::class, ['label' => 'Échéance', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false, 'invalid_message' => 'Date invalide.'])
            ->add('description', TextareaType::class, ['label' => 'Détails', 'required' => false, 'attr' => ['rows' => 5, 'maxlength' => 4000]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Tache::class]);
    }
}
