<?php

namespace App\Controller;

use App\Entity\Client;
use App\Form\ClientType;
use App\Repository\ClientRepository;
use App\Security\Permission;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Clients rattachés aux encaissements. Pas de suppression : un client reste lié à son historique. */
#[Route('/encaissements/clients')]
class ClientController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ActivityLogger $activity)
    {
    }

    #[IsGranted(Permission::ENCAISSEMENT_VIEW)]
    #[Route('', name: 'app_clients', methods: 'GET')]
    public function index(ClientRepository $clients): Response
    {
        return $this->render('client/index.html.twig', ['clients' => $clients->findAllByName()]);
    }

    #[IsGranted(Permission::ENCAISSEMENT_EDIT)]
    #[Route('/nouveau', name: 'app_client_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $client = new Client();
        $form = $this->createForm(ClientType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($client);
            $this->em->flush();
            $this->activity->log('client.create', ['id' => $client->getId(), 'nom' => $client->getName(), 'ifu' => $client->getIfu()]);
            $this->addFlash('success', sprintf('Client %s créé.', $client->getName()));

            return $this->redirectToRoute('app_encaissement_new', ['client' => $client->getId()]);
        }

        return $this->render('client/form.html.twig', ['form' => $form, 'client' => $client, 'isNew' => true]);
    }

    #[IsGranted(Permission::ENCAISSEMENT_EDIT)]
    #[Route('/{id}/modifier', name: 'app_client_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Client $client): Response
    {
        $before = ['nom' => $client->getName(), 'ifu' => $client->getIfu()];
        $form = $this->createForm(ClientType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $after = ['nom' => $client->getName(), 'ifu' => $client->getIfu()];
            if ($before !== $after) {
                $this->activity->log('client.update', ['id' => $client->getId(), 'avant' => $before, 'apres' => $after]);
            }
            $this->addFlash('success', sprintf('Client %s mis à jour. Les encaissements déjà saisis gardent leurs taux.', $client->getName()));

            return $this->redirectToRoute('app_clients');
        }

        return $this->render('client/form.html.twig', ['form' => $form, 'client' => $client, 'isNew' => false]);
    }
}
