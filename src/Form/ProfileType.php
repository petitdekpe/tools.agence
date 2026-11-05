<?php

namespace App\Form;

use App\Entity\Personnel;
use App\Entity\PersonnelDocument;
use App\Service\PersonnelFiles;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;

/**
 * Ce qu'une personne complète elle-même dans sa fiche du personnel. Le poste, le contrat et les dates d'entrée et de départ
 * restent à la charge des RH (voir PersonnelType). Les fichiers ne sont pas des champs de l'entité : le contrôleur les range.
 */
class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = static fn (string $label, array $extra = []): array => $extra + [
            'label' => $label,
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'required' => false,
            'invalid_message' => 'Date invalide.',
        ];

        $builder
            ->add('phone', PhoneNumberType::class, ['label' => 'Téléphone'])
            ->add('personalEmail', EmailType::class, ['label' => 'E-mail personnel', 'required' => false, 'attr' => ['maxlength' => 180], 'help' => 'Pour vous joindre hors du travail. Visible des RH seulement.'])
            ->add('birthDate', DateType::class, $date('Date de naissance', ['help' => 'Votre signe astrologique s\'en déduit automatiquement.']))
            ->add('adventureStartedAt', DateType::class, $date('Début de l\'aventure PROCOM', ['help' => 'Le jour où tout a commencé pour vous. Différent des dates de contrat.']))
            ->add('residence', TextType::class, ['label' => 'Lieu de résidence', 'required' => false, 'attr' => ['maxlength' => 255, 'placeholder' => 'Ex. : Fidjrossè, Cotonou, derrière la pharmacie']])
            ->add('residenceMapUrl', UrlType::class, ['label' => 'Lien Google Maps', 'required' => false, 'default_protocol' => null, 'attr' => ['maxlength' => 500, 'placeholder' => 'https://maps.app.goo.gl/...'], 'help' => 'Dans Google Maps : Partager, puis Copier le lien. Sans lien, une recherche Maps sur votre indication est proposée.'])
            ->add('emergency1Name', TextType::class, ['label' => 'Contact d\'urgence 1 : nom', 'required' => false, 'attr' => ['maxlength' => 120, 'placeholder' => 'Nom et lien (ex. : Awa, sœur)']])
            ->add('emergency1Phone', PhoneNumberType::class, ['label' => 'Contact d\'urgence 1 : téléphone'])
            ->add('emergency2Name', TextType::class, ['label' => 'Contact d\'urgence 2 : nom', 'required' => false, 'attr' => ['maxlength' => 120, 'placeholder' => 'Nom et lien (ex. : Koffi, ami)']])
            ->add('emergency2Phone', PhoneNumberType::class, ['label' => 'Contact d\'urgence 2 : téléphone']);

        foreach (Personnel::SOCIALS as $key => [$label]) {
            $builder->add('social_'.$key, TextType::class, [
                'label' => $label,
                'required' => false,
                'property_path' => 'socials['.$key.']',
                'attr' => ['maxlength' => 255, 'placeholder' => 'Lien du profil ou @identifiant'],
            ]);
        }

        $file = static fn (string $kind, string $label, string $help): array => [
            'label' => $label,
            'help' => $help,
            'mapped' => false,
            'required' => false,
            'constraints' => [new File(
                maxSize: PersonnelFiles::MAX_MB[$kind].'M',
                mimeTypes: array_keys(PersonnelFiles::ALLOWED[$kind]),
                maxSizeMessage: 'Fichier trop lourd ({{ size }} {{ suffix }}) : '.PersonnelFiles::MAX_MB[$kind].' Mo maximum.',
                mimeTypesMessage: 'Format non accepté.',
            )],
        ];

        $builder
            ->add('identity', FileType::class, $file(PersonnelDocument::IDENTITY, 'Pièce d\'identité', 'PDF, JPG ou PNG, 5 Mo max. Visible des RH seulement.'))
            ->add('cv', FileType::class, $file(PersonnelDocument::CV, 'CV', 'PDF, DOC ou DOCX, 5 Mo max.'))
            ->add('diploma', FileType::class, $file(PersonnelDocument::DIPLOMA, 'Diplôme le plus récent', 'PDF, JPG ou PNG, 5 Mo max.'))
            ->add('photos', FileType::class, [
                'label' => 'Ajouter des photos',
                'help' => 'JPG, PNG ou WebP, 3 Mo max chacune, '.PersonnelDocument::MAX_PHOTOS.' photos au total.',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp'],
                'constraints' => [
                    new Count(max: PersonnelDocument::MAX_PHOTOS, maxMessage: 'Quatre photos au maximum.'),
                    new All([new File(
                        maxSize: PersonnelFiles::MAX_MB[PersonnelDocument::PHOTO].'M',
                        mimeTypes: array_keys(PersonnelFiles::ALLOWED[PersonnelDocument::PHOTO]),
                        maxSizeMessage: 'Photo trop lourde ({{ size }} {{ suffix }}) : 3 Mo maximum.',
                        mimeTypesMessage: 'Les photos doivent être en JPG, PNG ou WebP.',
                    )]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Personnel::class]);
    }
}
