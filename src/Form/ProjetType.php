<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Personnel;
use App\Entity\Projet;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Saisie d'un projet : le client est facultatif (projet interne). */
class ProjetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'Nom du projet', 'attr' => ['maxlength' => 160, 'placeholder' => 'Ex. Campagne de lancement saison 2']])
            ->add('client', EntityType::class, [
                'label' => 'Client',
                'class' => Client::class,
                'required' => false,
                'placeholder' => 'Aucun (projet interne)',
                'choice_label' => static fn (Client $c): string => $c->getName(),
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
            ])
            ->add('responsable', EntityType::class, [
                'label' => 'Responsable du projet',
                'class' => Personnel::class,
                'required' => false,
                'placeholder' => 'Non attribué',
                'choice_label' => static fn (Personnel $p): string => $p->getFullName(),
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('p')
                    ->andWhere('p.deletedAt IS NULL')->andWhere('p.leftAt IS NULL OR p.leftAt > :today')
                    ->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable')
                    ->orderBy('p.lastName', 'ASC')->addOrderBy('p.firstName', 'ASC'),
            ])
            ->add('statut', ChoiceType::class, ['label' => 'Statut', 'choices' => array_flip(Projet::STATUTS)])
            ->add('dateDebut', DateType::class, ['label' => 'Début', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false, 'invalid_message' => 'Date invalide.'])
            ->add('echeance', DateType::class, ['label' => 'Échéance', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false, 'invalid_message' => 'Date invalide.'])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'attr' => ['rows' => 5, 'maxlength' => 4000]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Projet::class]);
    }
}
