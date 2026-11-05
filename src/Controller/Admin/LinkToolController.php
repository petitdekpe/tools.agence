<?php

namespace App\Controller\Admin;

use App\Entity\LinkTool;
use App\Form\LinkToolType;
use App\Repository\LinkToolRepository;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Gestion des outils de type lien, depuis les réglages du site. Réservé à l'administrateur. */
#[Route('/admin/settings/liens')]
#[IsGranted('ROLE_ADMIN')]
class LinkToolController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LinkToolRepository $links,
        private readonly ActivityLogger $activity,
    ) {
    }

    #[Route('/nouveau', name: 'app_admin_link_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $link = new LinkTool();
        $form = $this->createForm(LinkToolType::class, $link);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $link->setPosition($this->links->nextPosition());
            $this->em->persist($link);
            $this->em->flush();
            $this->activity->log('lien.create', $this->details($link));
            $this->addFlash('success', sprintf('Lien « %s » ajouté.', $link->getTitle()));

            return $this->redirectToRoute('app_admin_settings');
        }

        return $this->render('admin/links/form.html.twig', ['form' => $form, 'link' => $link, 'isNew' => true]);
    }

    #[Route('/{id}/modifier', name: 'app_admin_link_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, LinkTool $link): Response
    {
        $before = $this->details($link);
        $form = $this->createForm(LinkToolType::class, $link);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $after = $this->details($link);
            if ($before !== $after) {
                $this->activity->log('lien.update', ['id' => $link->getId(), 'avant' => $before, 'apres' => $after]);
                $this->addFlash('success', sprintf('Lien « %s » mis à jour.', $link->getTitle()));
            } else {
                $this->addFlash('success', 'Aucun changement.');
            }

            return $this->redirectToRoute('app_admin_settings');
        }

        return $this->render('admin/links/form.html.twig', ['form' => $form, 'link' => $link, 'isNew' => false]);
    }

    #[Route('/{id}/deplacer', name: 'app_admin_link_move', methods: 'POST', requirements: ['id' => '\d+'])]
    public function move(Request $request, LinkTool $link): Response
    {
        $this->checkCsrf($request, $link);
        $this->links->move($link, 'up' === $request->request->get('sens') ? -1 : 1);

        return $this->redirectToRoute('app_admin_settings');
    }

    #[Route('/{id}/supprimer', name: 'app_admin_link_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function delete(Request $request, LinkTool $link): Response
    {
        $this->checkCsrf($request, $link);

        $details = $this->details($link);
        $this->em->remove($link);
        $this->em->flush();
        $this->activity->log('lien.delete', $details);
        $this->addFlash('success', sprintf('Lien « %s » supprimé.', $details['nom']));

        return $this->redirectToRoute('app_admin_settings');
    }

    private function checkCsrf(Request $request, LinkTool $link): void
    {
        if (!$this->isCsrfTokenValid('lien_'.$link->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    /** @return array<string, mixed> */
    private function details(LinkTool $link): array
    {
        return [
            'id' => $link->getId(),
            'nom' => $link->getTitle(),
            'adresse' => $link->getUrl(),
            'categorie' => $link->getCategory(),
            'actif' => $link->isActive(),
            'roles' => $link->getRoleCodes(),
        ];
    }
}
