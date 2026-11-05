<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Personnel;
use App\Entity\Publication;
use App\Service\Choices;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Saisie d'une publication. Le statut « validé » n'est proposé qu'à ceux qui peuvent valider (option can_validate). */
class PublicationType extends AbstractType
{
    public function __construct(private readonly Choices $choices)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Publication $publication */
        $publication = $builder->getData();
        $statuts = $options['can_validate'] || Publication::VALIDE === $publication->getStatut()
            ? Publication::STATUTS
            : array_diff_key(Publication::STATUTS, [Publication::VALIDE => 1]);

        $builder
            ->add('titre', TextType::class, ['label' => 'Titre', 'attr' => ['maxlength' => 160, 'placeholder' => 'Ex. Teaser de la saison 2']])
            ->add('client', EntityType::class, [
                'label' => 'Client',
                'class' => Client::class,
                'required' => false,
                'placeholder' => 'PROCOM (publication interne)',
                'choice_label' => static fn (Client $c): string => $c->getName(),
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
            ])
            ->add('canal', ChoiceType::class, ['label' => 'Canal', 'choices' => array_flip($this->choices->labels('publication_canal'))])
            ->add('format', ChoiceType::class, ['label' => 'Format', 'choices' => array_flip($this->choices->labels('publication_format'))])
            ->add('datePublication', DateType::class, [
                'label' => 'Date de publication',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'invalid_message' => 'Date invalide.',
            ])
            ->add('heure', TimeType::class, [
                'label' => 'Heure (facultatif)',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'invalid_message' => 'Heure invalide.',
            ])
            ->add('statut', ChoiceType::class, ['label' => 'Statut', 'choices' => array_flip($statuts)])
            ->add('responsable', EntityType::class, [
                'label' => 'Responsable',
                'class' => Personnel::class,
                'required' => false,
                'placeholder' => 'Non attribuée',
                'choice_label' => static fn (Personnel $p): string => $p->getFullName(),
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('p')
                    ->andWhere('p.deletedAt IS NULL')->andWhere('p.leftAt IS NULL OR p.leftAt > :today')
                    ->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable')
                    ->orderBy('p.lastName', 'ASC')->addOrderBy('p.firstName', 'ASC'),
            ])
            ->add('contenu', TextareaType::class, [
                'label' => 'Texte',
                'required' => false,
                'attr' => ['rows' => 8, 'maxlength' => 8000],
                'help' => 'Légende, script ou brouillon. Pensez à le faire relire avec le Correcteur média avant validation.',
            ])
            ->add('lien', UrlType::class, [
                'label' => 'Lien de la publication en ligne',
                'required' => false,
                'default_protocol' => null,
                'attr' => ['placeholder' => 'https://'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Publication::class, 'can_validate' => false]);
        $resolver->setAllowedTypes('can_validate', 'bool');
    }
}
