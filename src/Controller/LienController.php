<?php

namespace App\Controller;

use App\Entity\LinkTool;
use App\Service\ActivityLogger;
use App\Service\ToolCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Passerelle vers un outil de type lien : vérifie que le rôle y a droit, trace l'ouverture puis redirige. */
class LienController extends AbstractController
{
    #[Route('/liens/{id}', name: 'app_lien', methods: 'GET', requirements: ['id' => '\d+'])]
    public function open(LinkTool $link, ToolCatalog $catalog, ActivityLogger $activity): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        // Un lien inactif ou réservé à d'autres rôles n'existe pas pour l'utilisateur.
        if (!$catalog->canOpen($link)) {
            throw $this->createNotFoundException('Lien introuvable.');
        }
        // Seules des adresses http(s) sont suivies, même si la base contenait autre chose.
        if (!preg_match('#^https?://#i', $link->getUrl())) {
            $this->addFlash('error', sprintf('L\'adresse de « %s » est invalide.', $link->getTitle()));

            return $this->redirectToRoute('app_home');
        }

        $activity->log('tool.open', ['tool' => $link->getTitle(), 'adresse' => $link->getUrl()]);

        return $this->redirect($link->getUrl());
    }
}
