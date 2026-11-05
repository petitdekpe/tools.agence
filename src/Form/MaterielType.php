<?php

namespace App\Form;

use App\Entity\Materiel;
use App\Service\Choices;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MaterielType extends AbstractType
{
    public function __construct(private readonly Choices $choices)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('article', ChoiceType::class, [
                'label' => 'Article de référence',
                'required' => false,
                'placeholder' => 'Aucun (saisie libre)',
                'choices' => array_flip($this->choices->labels('materiel_article')),
                'help' => 'Optionnel. Laissez la désignation vide pour reprendre le nom de l\'article. Liste modifiable dans Administration > listes de choix.',
            ])
            ->add('designation', TextType::class, ['label' => 'Désignation'])
            ->add('category', ChoiceType::class, ['label' => 'Catégorie', 'choices' => array_flip($this->choices->labels('materiel_category'))])
            ->add('quantity', IntegerType::class, ['label' => 'Quantité', 'attr' => ['min' => 1, 'step' => 1], 'invalid_message' => 'Quantité invalide.'])
            ->add('reference', TextType::class, ['label' => 'Référence', 'required' => false, 'help' => 'Numéro de série ou code d\'inventaire. Unique si renseignée.'])
            ->add('etat', ChoiceType::class, ['label' => 'État', 'choices' => array_flip($this->choices->labels('materiel_etat'))])
            ->add('purchasedAt', DateType::class, [
                'label' => 'Date d\'achat',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => ['max' => date('Y-m-d')],
                'invalid_message' => 'Date invalide.',
            ])
            ->add('purchaseValue', NumberType::class, [
                'label' => 'Valeur d\'achat (FCFA)',
                'required' => false,
                'input' => 'string',
                'scale' => 0,
                'html5' => true,
                'attr' => ['min' => 0, 'step' => 1, 'inputmode' => 'numeric'],
                'invalid_message' => 'Montant invalide.',
            ])
            ->add('observation', TextareaType::class, ['label' => 'Observation', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 2000]]);

        // Désignation laissée vide : on reprend le nom de l'article choisi.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (\is_array($data) && '' === trim((string) ($data['designation'] ?? '')) && '' !== (string) ($data['article'] ?? '')) {
                $data['designation'] = $this->choices->labels('materiel_article')[$data['article']] ?? '';
                $event->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Materiel::class]);
    }
}
