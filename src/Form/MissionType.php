<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Mission;
use App\Entity\Personnel;
use App\Entity\Projet;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Programmation d'une mission : lieu, période, chef de mission et équipe. Le retour de terrain se saisit à part. */
class MissionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $inPost = static fn ($repository) => $repository->createQueryBuilder('p')
            ->andWhere('p.deletedAt IS NULL')->andWhere('p.leftAt IS NULL OR p.leftAt > :today')
            ->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable')
            ->orderBy('p.lastName', 'ASC')->addOrderBy('p.firstName', 'ASC');

        $builder
            ->add('titre', TextType::class, ['label' => 'Titre de la mission', 'attr' => ['maxlength' => 160, 'placeholder' => 'Ex. Tournage du clip à Ouidah']])
            ->add('projet', EntityType::class, [
                'label' => 'Projet',
                'class' => Projet::class,
                'required' => false,
                'placeholder' => 'Aucun projet',
                'choice_label' => static fn (Projet $p): string => $p->getNom().($p->getClient() ? ' · '.$p->getClient()->getName() : ''),
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('p')
                    ->andWhere('p.deletedAt IS NULL')->andWhere('p.statut <> :annule')->setParameter('annule', Projet::ANNULE)->orderBy('p.nom', 'ASC'),
            ])
            ->add('client', EntityType::class, [
                'label' => 'Client',
                'class' => Client::class,
                'required' => false,
                'placeholder' => 'Celui du projet, s\'il y en a un',
                'choice_label' => static fn (Client $c): string => $c->getName(),
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
            ])
            ->add('lieu', TextType::class, ['label' => 'Lieu', 'attr' => ['maxlength' => 200, 'placeholder' => 'Ville, site ou adresse']])
            ->add('dateDebut', DateType::class, ['label' => 'Premier jour', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'invalid_message' => 'Date invalide.'])
            ->add('dateFin', DateType::class, ['label' => 'Dernier jour (inclus)', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'invalid_message' => 'Date invalide.'])
            ->add('heureDebut', TimeType::class, ['label' => 'Heure de départ (facultatif)', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false, 'invalid_message' => 'Heure invalide.'])
            ->add('statut', ChoiceType::class, ['label' => 'Statut', 'choices' => array_flip(Mission::STATUTS)])
            ->add('responsable', EntityType::class, [
                'label' => 'Chef de mission',
                'class' => Personnel::class,
                'required' => false,
                'placeholder' => 'Non désigné',
                'choice_label' => static fn (Personnel $p): string => $p->getFullName(),
                'query_builder' => $inPost,
            ])
            ->add('equipe', EntityType::class, [
                'label' => 'Équipe',
                'class' => Personnel::class,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
                'choice_label' => static fn (Personnel $p): string => $p->getFullName().($p->getJobTitle() ? ' · '.$p->getJobTitle() : ''),
                'query_builder' => $inPost,
            ])
            ->add('objectifs', TextareaType::class, [
                'label' => 'Objectifs et consignes',
                'required' => false,
                'attr' => ['rows' => 5, 'maxlength' => 4000],
                'help' => 'Ce que l\'équipe doit rapporter, matériel à emporter, contacts sur place…',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Mission::class]);
    }
}
