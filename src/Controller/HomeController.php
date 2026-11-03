<?php

namespace App\Controller;

use App\Service\ToolCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(ToolCatalog $catalog): Response
    {
        // Un outil soumis à une permission n'apparaît que pour ceux qui l'ont.
        $tools = array_values(array_filter($catalog->all(), fn (array $tool): bool => !isset($tool['permission']) || $this->isGranted($tool['permission'])));

        return $this->render('home/index.html.twig', ['tools' => $tools]);
    }
}
