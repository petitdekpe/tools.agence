<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Document;
use App\Service\DocumentNumbering;
use App\Service\EncaissementCalculator;
use App\Service\FiscalSettings;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
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
 * Saisie d'une facture ou d'un devis. Le type est fixé à la création et ne se change plus. Le HT et la TVA sont
 * recalculés côté serveur avant la validation ; sans numéro saisi, le document reçoit le suivant de sa série.
 */
class DocumentType extends AbstractType
{
    public function __construct(
        private readonly EncaissementCalculator $calculator,
        private readonly FiscalSettings $settings,
        private readonly DocumentNumbering $numbering,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Document $document */
        $document = $builder->getData();
        $isFacture = $document->isFacture();

        $builder
            ->add('reference', TextType::class, [
                'label' => 'Numéro',
                'required' => false,
                'attr' => ['maxlength' => 30, 'placeholder' => 'Automatique'],
                'help' => 'Laissez vide pour numéroter automatiquement (ex. '.(Document::PREFIXES[$document->getType()]).'-'.date('Y').'-001).',
            ])
            ->add('client', EntityType::class, [
                'label' => 'Client',
                'class' => Client::class,
                'choice_label' => static fn (Client $c): string => $c->getName(),
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
                'placeholder' => 'Choisir un client',
            ])
            ->add('objet', TextType::class, ['label' => 'Objet', 'attr' => ['maxlength' => 200]])
            ->add('dateEmission', DateType::class, [
                'label' => 'Date d\'émission',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'invalid_message' => 'Date invalide.',
            ])
            ->add('dateLimite', DateType::class, [
                'label' => $isFacture ? 'Échéance de paiement' : 'Valable jusqu\'au',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'invalid_message' => 'Date invalide.',
            ])
            ->add('montantTtc', NumberType::class, [
                'label' => 'Montant TTC (FCFA)',
                'input' => 'string',
                'scale' => 0,
                'html5' => true,
                'attr' => ['min' => 1, 'step' => 1, 'inputmode' => 'numeric'],
                'invalid_message' => 'Montant invalide.',
                'help' => 'Le HT et la TVA sont calculés à l\'enregistrement, au taux de '.rtrim(rtrim($this->settings->get()->getTauxTva(), '0'), '.').' % pour un nouveau document.',
            ])
            ->add('statut', ChoiceType::class, [
                'label' => 'Statut',
                'choices' => array_flip($document->getStatuts()),
            ])
            ->add('observation', TextareaType::class, ['label' => 'Observation', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 2000]]);

        // Le calcul passe avant la validation (priorité 10 > 0) pour que les contrôles voient les vrais montants.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            /** @var Document $document */
            $document = $event->getData();
            $isNew = null === $document->getId();

            if ($isNew) {
                $document->setTauxTva($this->settings->get()->getTauxTva());
            }
            if ('' === $document->getReference() && $isNew && $document->getDateEmission()) {
                $document->setReference($this->numbering->next($document->getType(), $document->getDateEmission()));
            }

            $ttc = $document->getMontantTtc();
            if (null !== $ttc && is_numeric($ttc) && EncaissementCalculator::toInt($ttc) > 0) {
                $amounts = $this->calculator->compute($ttc, false, $document->getTauxTva(), '0');
                $document->setMontantHt(EncaissementCalculator::format($amounts->ht))->setMontantTva(EncaissementCalculator::format($amounts->tvaFacturee));
            } else {
                $document->setMontantHt('0.00')->setMontantTva('0.00');
            }
        }, 10);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Document::class]);
    }
}
