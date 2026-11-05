<?php

namespace App\Controller;

use App\Entity\Personnel;
use App\Entity\PersonnelDocument;
use App\Entity\User;
use App\Form\ProfileType;
use App\Repository\PersonnelDocumentRepository;
use App\Repository\PersonnelRepository;
use App\Service\ActivityLogger;
use App\Service\ChangeSet;
use App\Service\PersonnelFiles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page profil de l'utilisateur connecté : son compte, et sa fiche du personnel qu'il complète lui-même (naissance, résidence,
 * contacts d'urgence, réseaux, documents, photos). Ouverte à tout compte, sans permission particulière : chacun ne voit que sa fiche.
 */
#[Route('/profil')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PersonnelRepository $personnel,
        private readonly PersonnelDocumentRepository $documents,
        private readonly PersonnelFiles $files,
        private readonly ActivityLogger $activity,
    ) {
    }

    #[Route('', name: 'app_profile', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $person = $this->personnel->findForUser($user);
        if (!$person) {
            return $this->render('profile/index.html.twig', ['person' => null, 'form' => null, 'documents' => [], 'kinds' => PersonnelDocument::KINDS, 'missing' => [], 'completion' => 0]);
        }

        $before = $person->profileSnapshot();
        $form = $this->createForm(ProfileType::class, $person);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var list<UploadedFile> $photos */
            $photos = array_values(array_filter((array) $form->get('photos')->getData(), static fn ($f): bool => $f instanceof UploadedFile));
            $room = PersonnelDocument::MAX_PHOTOS - $this->documents->countPhotos($person);
            if (\count($photos) > $room) {
                $form->get('photos')->addError(new FormError($room > 0
                    ? sprintf('Il ne reste de la place que pour %d photo%s (%d au maximum). Supprimez-en une pour en ajouter davantage.', $room, $room > 1 ? 's' : '', PersonnelDocument::MAX_PHOTOS)
                    : sprintf('Vous avez déjà %d photos : supprimez-en une pour en ajouter.', PersonnelDocument::MAX_PHOTOS)));
            } else {
                $stored = $this->storeFiles($form, $person, $photos);
                if (null !== $stored) {
                    $person->touch();
                    $this->em->flush();
                    $details = ['id' => $person->getId(), 'personne' => $person->getFullName(), 'champs' => array_keys(ChangeSet::diff($before, $person->profileSnapshot())['apres'] ?? [])];
                    if ($stored) {
                        $details['documents'] = $stored;
                    }
                    if ($details['champs'] || $stored) {
                        $this->activity->log('personnel.profile', $details);
                    }
                    $this->addFlash('success', 'Profil mis à jour.');

                    return $this->redirectToRoute('app_profile');
                }
            }
        }

        $documents = $this->documents->byKind($person);
        $missing = $this->missing($person, $documents);

        return $this->render('profile/index.html.twig', [
            'person' => $person,
            'form' => $form,
            'documents' => $documents,
            'kinds' => PersonnelDocument::KINDS,
            'missing' => $missing,
            'completion' => (int) round(100 * (self::ITEMS - \count($missing)) / self::ITEMS),
        ]);
    }

    /** Nombre d'éléments du profil que la personne complète (voir missing()). */
    private const ITEMS = 12;

    /**
     * Ce qu'il manque encore au profil.
     *
     * @param array<string, list<PersonnelDocument>> $documents
     *
     * @return list<string>
     */
    private function missing(Personnel $person, array $documents): array
    {
        $checks = [
            'téléphone' => (bool) $person->getPhone(),
            'e-mail personnel' => (bool) $person->getPersonalEmail(),
            'date de naissance' => null !== $person->getBirthDate(),
            'début de l\'aventure' => null !== $person->getAdventureStartedAt(),
            'lieu de résidence' => (bool) $person->getResidence(),
            'contact d\'urgence 1' => $person->getEmergency1Name() && $person->getEmergency1Phone(),
            'contact d\'urgence 2' => $person->getEmergency2Name() && $person->getEmergency2Phone(),
            'réseaux sociaux' => [] !== $person->getSocials(),
            'pièce d\'identité' => [] !== $documents[PersonnelDocument::IDENTITY],
            'CV' => [] !== $documents[PersonnelDocument::CV],
            'diplôme' => [] !== $documents[PersonnelDocument::DIPLOMA],
            'photo' => [] !== $documents[PersonnelDocument::PHOTO],
        ];

        return array_keys(array_filter($checks, static fn ($done): bool => !$done));
    }

    #[Route('/documents/{id}/supprimer', name: 'app_profile_document_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function deleteDocument(Request $request, PersonnelDocument $document): Response
    {
        $user = $this->getUser();
        // Chacun ne supprime que ses propres fichiers : un fichier d'un autre n'existe pas pour lui.
        if (!$user instanceof User || $document->getPersonnel()->getUser()?->getId() !== $user->getId() || $document->getPersonnel()->isDeleted()) {
            throw $this->createNotFoundException('Fichier introuvable.');
        }
        if (!$this->isCsrfTokenValid('document_'.$document->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $label = $document->getKindLabel();
        $details = ['id' => $document->getPersonnel()->getId(), 'personne' => $document->getPersonnel()->getFullName(), 'documents_supprimes' => [$label]];
        $this->files->remove($document);
        $this->activity->log('personnel.profile', $details);
        $this->addFlash('success', sprintf('%s supprimé.', $label));

        return $this->redirectToRoute('app_profile');
    }

    /**
     * Range la pièce d'identité, le CV, le diplôme et les nouvelles photos.
     *
     * @param list<UploadedFile> $photos
     *
     * @return list<string>|null les types de fichiers enregistrés, ou null si un fichier a été refusé (rien n'est alors gardé)
     */
    private function storeFiles(FormInterface $form, Personnel $person, array $photos): ?array
    {
        $queue = [];
        foreach ([PersonnelDocument::IDENTITY => 'identity', PersonnelDocument::CV => 'cv', PersonnelDocument::DIPLOMA => 'diploma'] as $kind => $field) {
            if (($file = $form->get($field)->getData()) instanceof UploadedFile) {
                $queue[] = [$kind, $file, $field];
            }
        }
        foreach ($photos as $photo) {
            $queue[] = [PersonnelDocument::PHOTO, $photo, 'photos'];
        }

        // Tout est contrôlé avant d'écrire quoi que ce soit : un fichier refusé ne doit pas supprimer l'ancien.
        foreach ($queue as [$kind, $file, $field]) {
            if (!isset(PersonnelFiles::ALLOWED[$kind][(string) $file->getMimeType()])) {
                $form->get($field)->addError(new FormError('Format de fichier non accepté.'));

                return null;
            }
        }

        $stored = [];
        foreach ($queue as [$kind, $file, $field]) {
            try {
                $this->files->store($person, $kind, $file);
                $stored[$kind] = PersonnelDocument::KINDS[$kind];
            } catch (\DomainException $e) {
                $form->get($field)->addError(new FormError($e->getMessage()));

                return null;
            }
        }

        return array_values($stored);
    }
}
