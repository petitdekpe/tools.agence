<?php

namespace App\Form;

use App\Entity\Depense;
use App\Service\Choices;
use App\Service\EncaissementCalculator;
use App\Service\FiscalSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie d'une dépense. Le HT et la TVA déductible sont recalculés côté serveur avant la validation ; le taux de TVA est figé
 * à la création. Une date de paiement renseignée vaut décaissement et exige un mode de paiement.
 */
class DepenseType extends AbstractType
{
    public function __construct(private readonly EncaissementCalculator $calculator, private readonly FiscalSettings $settings, private readonly Choices $choices)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dateDepense', DateType::class, [
                'label' => 'Date de la dépense',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['max' => date('Y-m-d')],
                'invalid_message' => 'Date invalide.',
            ])
            ->add('fournisseur', TextType::class, [
                'label' => 'Fournisseur',
                'attr' => ['maxlength' => 160, 'list' => 'fournisseurs', 'autocomplete' => 'off'],
            ])
            ->add('categorie', ChoiceType::class, ['label' => 'Catégorie', 'choices' => array_flip($this->choices->labels('depense_categorie'))])
            ->add('libelle', TextType::class, ['label' => 'Libellé', 'attr' => ['maxlength' => 160, 'placeholder' => 'Ex. Location du studio, mars']])
            ->add('numeroPiece', TextType::class, ['label' => 'N° de facture ou de reçu', 'required' => false, 'attr' => ['maxlength' => 60]])
            ->add('montantTtc', NumberType::class, [
                'label' => 'Montant TTC (FCFA)',
                'input' => 'string',
                'scale' => 0,
                'html5' => true,
                'attr' => ['min' => 1, 'step' => 1, 'inputmode' => 'numeric'],
                'invalid_message' => 'Montant invalide.',
            ])
            ->add('tvaApplicable', CheckboxType::class, [
                'label' => 'Le fournisseur facture la TVA (TVA déductible)',
                'required' => false,
                'help' => 'Taux appliqué à une nouvelle dépense : '.rtrim(rtrim($this->settings->get()->getTauxTva(), '0'), '.').' %.',
            ])
            ->add('echeance', DateType::class, [
                'label' => 'Échéance de paiement',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'invalid_message' => 'Date invalide.',
            ])
            ->add('datePaiement', DateType::class, [
                'label' => 'Payée le',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => ['max' => date('Y-m-d')],
                'invalid_message' => 'Date invalide.',
                'help' => 'Laissez vide tant que la dépense reste à payer.',
            ])
            ->add('mode', ChoiceType::class, [
                'label' => 'Mode de paiement',
                'required' => false,
                'placeholder' => 'Non payée',
                'choices' => array_flip($this->choices->labels('depense_mode')),
            ])
            ->add('observation', TextareaType::class, ['label' => 'Observation', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 2000]]);

        // Le calcul passe avant la validation (priorité 10 > 0).
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            /** @var Depense $depense */
            $depense = $event->getData();
            if (null === $depense->getId()) {
                $depense->setTauxTva($this->settings->get()->getTauxTva());
            }

            $ttc = $depense->getMontantTtc();
            if (null === $ttc || !is_numeric($ttc) || EncaissementCalculator::toInt($ttc) <= 0) {
                $depense->setMontantHt('0.00')->setMontantTva('0.00');

                return;
            }
            if ($depense->isTvaApplicable()) {
                $amounts = $this->calculator->compute($ttc, false, $depense->getTauxTva(), '0');
                $depense->setMontantHt(EncaissementCalculator::format($amounts->ht))->setMontantTva(EncaissementCalculator::format($amounts->tvaFacturee));
            } else {
                $depense->setMontantHt(EncaissementCalculator::format(EncaissementCalculator::toInt($ttc)))->setMontantTva('0.00');
            }
        }, 10);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Depense::class]);
    }
}
