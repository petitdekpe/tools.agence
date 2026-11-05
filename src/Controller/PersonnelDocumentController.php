<?php

namespace App\Controller;

use App\Entity\PersonnelDocument;
use App\Entity\User;
use App\Security\Permission;
use App\Service\ActivityLogger;
use App\Service\PersonnelFiles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lecture d'un fichier du dossier personnel (stocké hors du dossier public). Il est servi à son propriétaire et aux RH
 * (« personnel.edit ») ; les photos le sont aussi à ceux qui consultent les fiches (« personnel.view »).
 */
class PersonnelDocumentController extends AbstractController
{
    #[Route('/personnel/documents/{id}', name: 'app_personnel_document', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(PersonnelDocument $document, PersonnelFiles $files, ActivityLogger $activity): Response
    {
        $user = $this->getUser();
        $person = $document->getPersonnel();
        $owner = $user instanceof User && $person->getUser()?->getId() === $user->getId();
        $allowed = $owner
            || $this->isGranted(Permission::PERSONNEL_EDIT)
            || (PersonnelDocument::PHOTO === $document->getKind() && $this->isGranted(Permission::PERSONNEL_VIEW));
        // Un fichier qu'on n'a pas le droit de lire n'existe pas : même réponse que pour un identifiant inconnu.
        if (!$allowed || $person->isDeleted() || !is_file($path = $files->pathOf($document))) {
            throw $this->createNotFoundException('Fichier introuvable.');
        }

        if (!$owner && PersonnelDocument::PHOTO !== $document->getKind()) {
            $activity->log('personnel.document', ['id' => $person->getId(), 'personne' => $person->getFullName(), 'document' => $document->getKindLabel()]);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $document->getMimeType());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        $inline = $document->isImage() || 'application/pdf' === $document->getMimeType();
        $response->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->getKindLabel().'.'.pathinfo($path, \PATHINFO_EXTENSION),
            'document.'.pathinfo($path, \PATHINFO_EXTENSION),
        );

        return $response;
    }
}
