<?php

namespace App\Controller;

use App\Security\Permission;
use App\Service\ActivityLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Passerelle vers l'instance EspoCRM : trace l'ouverture puis redirige. */
class EspoCrmController extends AbstractController
{
    #[IsGranted(Permission::ESPOCRM_USE)]
    #[Route('/espocrm', name: 'app_espocrm', methods: 'GET')]
    public function index(ActivityLogger $activity, #[Autowire('%env(ESPOCRM_URL)%')] string $url): Response
    {
        if (!preg_match('#^https?://#i', $url)) {
            $this->addFlash('error', 'EspoCRM n\'est pas configuré (variable ESPOCRM_URL).');

            return $this->redirectToRoute('app_home');
        }

        $activity->log('tool.open', ['tool' => 'EspoCRM']);

        return $this->redirect($url);
    }
}
