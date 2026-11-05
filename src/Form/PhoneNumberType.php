<?php

namespace App\Form;

use App\Service\DialCodes;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Un numéro de téléphone en deux champs : l'indicatif (liste de pays) et le numéro. La valeur reste une seule chaîne
 * (« +229 0197000000 »), donc les colonnes existantes ne changent pas.
 */
class PhoneNumberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', ChoiceType::class, ['choices' => DialCodes::choices(), 'label' => false, 'required' => false, 'placeholder' => false, 'attr' => ['class' => 'phone-code', 'aria-label' => 'Indicatif du pays']])
            ->add('number', TelType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['class' => 'phone-number', 'maxlength' => 20, 'inputmode' => 'tel', 'autocomplete' => 'off', 'aria-label' => 'Numéro de téléphone'],
                'constraints' => [new Regex(pattern: '/^[0-9 ().\-]{4,20}$/', message: 'Numéro invalide : chiffres, espaces et tirets uniquement.')],
            ]);

        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): array => DialCodes::split($value),
            static function (?array $value): ?string {
                $number = trim(preg_replace('/\s+/', ' ', (string) ($value['number'] ?? '')) ?? '');
                if ('' === $number) {
                    return null;
                }
                $code = (string) ($value['code'] ?? '');

                return (\array_key_exists($code, DialCodes::COUNTRIES) ? $code : DialCodes::DEFAULT).' '.$number;
            },
        ));
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['attr']['class'] = trim(($view->vars['attr']['class'] ?? '').' phone-group');
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['compound' => true, 'required' => false, 'error_bubbling' => false, 'by_reference' => true]);
    }
}
