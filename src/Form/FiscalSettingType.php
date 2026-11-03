<?php

namespace App\Form;

use App\Entity\FiscalSetting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FiscalSettingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $rate = static fn (string $label, string $help): array => [
            'label' => $label,
            'help' => $help,
            'input' => 'string',
            'scale' => 2,
            'html5' => true,
            'attr' => ['min' => 0, 'max' => 100, 'step' => 0.01],
            'invalid_message' => 'Taux invalide.',
        ];

        $builder
            ->add('tauxTva', NumberType::class, $rate('Taux de TVA (%)', 'Appliqué aux nouveaux encaissements.'))
            ->add('tauxAibAvecIfu', NumberType::class, $rate('Taux d\'AIB avec IFU (%)', 'Pour les clients qui ont un IFU.'))
            ->add('tauxAibSansIfu', NumberType::class, $rate('Taux d\'AIB sans IFU (%)', 'Taux majoré pour les clients sans IFU.'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FiscalSetting::class]);
    }
}
