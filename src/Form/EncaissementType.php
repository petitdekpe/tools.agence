<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\Encaissement;
use App\Service\EncaissementCalculator;
use App\Service\FiscalSettings;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de saisie : seuls TTC, retenue de TVA et répartition sont lus. Les montants calculés
 * sont recalculés côté serveur avant la validation, donc la règle « répartition ≤ net reçu » porte
 * sur les vrais montants et jamais sur ce que le navigateur aurait envoyé.
 */
class EncaissementType extends AbstractType
{
    public function __construct(private readonly EncaissementCalculator $calculator, private readonly FiscalSettings $settings)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Les attributs data-* branchent les champs sur le contrôleur Stimulus encaissement_form (aperçu en direct).
        $amount = static fn (string $target, string $label, array $extra = []): array => $extra + [
            'label' => $label,
            'input' => 'string',
            'scale' => 0,
            'html5' => true,
            'attr' => ['min' => 0, 'step' => 1, 'inputmode' => 'numeric', 'data-encaissement-form-target' => $target, 'data-action' => 'input->encaissement-form#recalculate'],
            'invalid_message' => 'Montant invalide.',
        ];

        $builder
            ->add('dateEncaissement', DateType::class, [
                'label' => 'Date d\'encaissement',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['max' => date('Y-m-d')],
                'invalid_message' => 'Date invalide.',
            ])
            ->add('client', EntityType::class, [
                'label' => 'Client',
                'class' => Client::class,
                'choice_label' => static fn (Client $c): string => $c->getName().($c->hasIfu() ? '' : ' (sans IFU)'),
                'choice_attr' => static fn (Client $c): array => ['data-ifu' => $c->hasIfu() ? '1' : '0'],
                'attr' => ['data-encaissement-form-target' => 'client', 'data-action' => 'change->encaissement-form#recalculate'],
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
                'placeholder' => 'Choisir un client',
            ])
            ->add('nature', ChoiceType::class, [
                'label' => 'Nature du paiement',
                'choices' => array_flip(Encaissement::NATURES),
                'help' => 'Une avance ou un acompte est taxé comme un paiement de facture : la TVA est due dès l\'encaissement.',
            ])
            ->add('facture', EntityType::class, [
                'label' => 'Facture réglée',
                'class' => Document::class,
                'required' => false,
                'choice_label' => static fn (Document $d): string => $d->getReference().' · '.$d->getClient()?->getName().' · '.number_format((float) $d->getMontantTtc(), 0, '', ' ').' FCFA'.(Document::ANNULEE === $d->getStatut() ? ' (annulée)' : ''),
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('d')
                    ->andWhere('d.type = :type')->andWhere('d.deletedAt IS NULL')->andWhere('d.statut <> :draft')
                    ->setParameter('type', Document::TYPE_FACTURE)->setParameter('draft', Document::BROUILLON)
                    ->orderBy('d.dateEmission', 'DESC')->addOrderBy('d.id', 'DESC'),
                'placeholder' => 'Aucune (paiement sans facture suivie)',
                'help' => 'Rattache ce paiement à une facture : son reste à encaisser se met à jour dans Factures et devis.',
            ])
            ->add('montantTtc', NumberType::class, $amount('ttc', 'Montant TTC encaissé (FCFA)', ['attr' => ['min' => 1, 'step' => 1, 'inputmode' => 'numeric', 'data-encaissement-form-target' => 'ttc', 'data-action' => 'input->encaissement-form#recalculate']]))
            ->add('retenueTvaSource', CheckboxType::class, [
                'label' => 'Le client retient la TVA à la source',
                'required' => false,
                'attr' => ['data-encaissement-form-target' => 'retenue', 'data-action' => 'change->encaissement-form#recalculate'],
            ])
            ->add('montantEspecesMomo', NumberType::class, $amount('especes', 'Espèces / MoMo (FCFA)', ['empty_data' => '0']))
            ->add('montantChequeVirement', NumberType::class, $amount('cheque', 'Chèque / virement (FCFA)', ['empty_data' => '0']))
            ->add('observation', TextareaType::class, ['label' => 'Observation', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 2000]]);

        // Le calcul passe avant la validation (priorité 10 > 0) pour que les contrôles voient les vrais montants.
        $originalClient = null;
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use (&$originalClient): void {
            $originalClient = $event->getData()?->getClient();
        });
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use (&$originalClient): void {
            /** @var Encaissement $encaissement */
            $encaissement = $event->getData();
            $isNew = null === $encaissement->getId();
            // Les taux sont figés à la création ; une modification les garde, sauf si le client change
            // (son statut IFU peut alors changer le taux d'AIB).
            $this->calculator->apply($encaissement, $this->settings->get(), $isNew || $encaissement->getClient() !== $originalClient);
        }, 10);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Encaissement::class]);
    }
}
