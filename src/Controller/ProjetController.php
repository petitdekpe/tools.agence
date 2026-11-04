<?php

namespace App\Controller;

use App\Dto\MissionFilter;
use App\Dto\ProjetFilter;
use App\Entity\Client;
use App\Entity\Projet;
use App\Entity\Tache;
use App\Entity\User;
use App\Form\ProjetType;
use App\Form\TacheType;
use App\Repository\ClientRepository;
use App\Repository\MissionRepository;
use App\Repository\PersonnelRepository;
use App\Repository\ProjetRepository;
use App\Repository\TacheRepository;
use App\Security\Permission;
use App\Service\ActivityLogger;
use App\Service\KanbanOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Projets et leurs tâches, présentés en tableaux (colonnes de cartes que l'on déplace). Consulter exige « projet.view »,
 * créer, modifier et déplacer exige « projet.edit », supprimer un projet « projet.delete ». La suppression d'un projet est
 * logique ; celle d'une tâche est définitive.
 */
#[Route('/projets')]
class ProjetController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjetRepository $projets,
        private readonly TacheRepository $taches,
        private readonly ActivityLogger $activity,
    ) {
    }

    /** Le tableau des projets : une colonne par statut. */
    #[IsGranted(Permission::PROJET_VIEW)]
    #[Route('', name: 'app_projets', methods: 'GET')]
    public function board(Request $request, ClientRepository $clients, PersonnelRepository $personnel): Response
    {
        $filter = ProjetFilter::fromRequest($request);

        return $this->render('projet/board.html.twig', [
            'columns' => $this->projets->board($filter),
            'filter' => $filter,
            'dashboard' => $this->projets->dashboard(),
            'clients' => $clients->findAllByName(),
            'people' => $personnel->findInPost(),
        ]);
    }

    #[IsGranted(Permission::PROJET_VIEW)]
    #[Route('/liste', name: 'app_projets_list', methods: 'GET')]
    public function list(Request $request, ClientRepository $clients, PersonnelRepository $personnel): Response
    {
        $filter = ProjetFilter::fromRequest($request);
        [$rows, $total] = $this->projets->paginate($filter, self::PER_PAGE);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($filter->page > $pages) {
            $filter->page = $pages;
            [$rows] = $this->projets->paginate($filter, self::PER_PAGE);
        }

        return $this->render('projet/list.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $filter->page,
            'pages' => $pages,
            'filter' => $filter,
            'dashboard' => $this->projets->dashboard(),
            'clients' => $clients->findAllByName(),
            'people' => $personnel->findInPost(),
        ]);
    }

    #[IsGranted(Permission::PROJET_EDIT)]
    #[Route('/nouveau', name: 'app_projet_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $projet = new Projet();
        $statut = (string) $request->query->get('statut');
        if (isset(Projet::STATUTS[$statut])) {
            $projet->transition($statut);
        }
        $user = $this->getUser();
        if ($user instanceof User) {
            $projet->setCreatedBy($user);
        }
        if ($clientId = $request->query->getInt('client')) {
            $projet->setClient($this->em->getRepository(Client::class)->find($clientId));
        }

        $form = $this->createForm(ProjetType::class, $projet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $projet->setPosition($this->projets->nextPosition($projet->getStatut()));
            $this->em->persist($projet);
            $this->em->flush();
            $this->activity->log('projet.create', ['id' => $projet->getId()] + $projet->snapshot());
            $this->addFlash('success', sprintf('Projet « %s » créé.', $projet->getNom()));

            return $this->redirectToRoute('app_projet_show', ['id' => $projet->getId()]);
        }

        return $this->render('projet/form.html.twig', ['form' => $form, 'projet' => $projet, 'isNew' => true]);
    }

    /** Le tableau des tâches d'un projet. */
    #[IsGranted(Permission::PROJET_VIEW)]
    #[Route('/{id}', name: 'app_projet_show', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(int $id, MissionRepository $missions): Response
    {
        $projet = $this->findOr404($id);
        $filter = new MissionFilter();
        $filter->projet = $projet->getId();
        $filter->sort = 'date';
        $filter->dir = 'desc';
        [$projectMissions] = $missions->paginate($filter, 8);

        return $this->render('projet/show.html.twig', [
            'projet' => $projet,
            'columns' => $this->taches->board($projet),
            'missions' => $projectMissions,
        ]);
    }

    #[IsGranted(Permission::PROJET_EDIT)]
    #[Route('/{id}/modifier', name: 'app_projet_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $projet = $this->findOr404($id);
        $before = $projet->snapshot();
        $previousStatut = $projet->getStatut();

        $form = $this->createForm(ProjetType::class, $projet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($projet->getStatut() !== $previousStatut) {
                $projet->setPosition($this->projets->nextPosition($projet->getStatut())); // en bas de sa nouvelle colonne
            }
            $projet->touch();
            $this->em->flush();
            $this->logChanges('projet.update', $projet->getId(), $before, $projet->snapshot());
            $this->addFlash('success', 'Projet mis à jour.');

            return $this->redirectToRoute('app_projet_show', ['id' => $projet->getId()]);
        }

        return $this->render('projet/form.html.twig', ['form' => $form, 'projet' => $projet, 'isNew' => false]);
    }

    /** Déplace une carte du tableau des projets (appel de la page, en glisser-déposer). */
    #[IsGranted(Permission::PROJET_EDIT)]
    #[Route('/{id}/deplacer', name: 'app_projet_move', methods: 'POST', requirements: ['id' => '\d+'])]
    public function move(Request $request, int $id): JsonResponse
    {
        $projet = $this->findOr404($id);
        [$statut, $index] = $this->readMove($request, array_keys(Projet::STATUTS));

        $from = $projet->getStatutLabel();
        $changed = $projet->getStatut() !== $statut;
        if ($changed) {
            $projet->transition($statut);
        }
        KanbanOrder::place($this->projets->column($statut), $projet, $index);
        $this->em->flush();

        if ($changed) {
            $this->activity->log('projet.status', ['id' => $id, 'nom' => $projet->getNom(), 'de' => $from, 'vers' => $projet->getStatutLabel()]);
        }

        return $this->json(['ok' => true, 'statut' => $projet->getStatut(), 'label' => $projet->getStatutLabel()]);
    }

    #[IsGranted(Permission::PROJET_DELETE)]
    #[Route('/{id}/supprimer', name: 'app_projet_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $projet = $this->findOr404($id);
        $this->checkToken($request, 'projet_'.$id);

        $user = $this->getUser();
        $projet->softDelete($user instanceof User ? $user : null);
        $this->em->flush();
        $this->activity->log('projet.delete', ['id' => $id] + $projet->snapshot());
        $this->addFlash('success', 'Projet supprimé.');

        return $this->redirectToRoute('app_projets');
    }

    #[IsGranted(Permission::PROJET_EDIT)]
    #[Route('/{id}/taches/nouvelle', name: 'app_tache_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function newTask(Request $request, int $id): Response
    {
        $projet = $this->findOr404($id);
        $tache = (new Tache())->setProjet($projet);
        $statut = (string) $request->query->get('statut');
        if (isset(Tache::STATUTS[$statut])) {
            $tache->transition($statut);
        }
        $user = $this->getUser();
        if ($user instanceof User) {
            $tache->setCreatedBy($user);
        }

        $form = $this->createForm(TacheType::class, $tache);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $tache->setPosition($this->taches->nextPosition($projet, $tache->getStatut()));
            $this->em->persist($tache);
            $projet->touch();
            $this->em->flush();
            $this->activity->log('tache.create', ['id' => $tache->getId()] + $tache->snapshot());
            $this->addFlash('success', 'Carte ajoutée.');

            return $this->redirectToRoute('app_projet_show', ['id' => $projet->getId()]);
        }

        return $this->render('projet/tache_form.html.twig', ['form' => $form, 'tache' => $tache, 'projet' => $projet, 'isNew' => true]);
    }

    #[IsGranted(Permission::PROJET_EDIT)]
    #[Route('/taches/{id}/modifier', name: 'app_tache_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editTask(Request $request, int $id): Response
    {
        $tache = $this->taskOr404($id);
        $before = $tache->snapshot();
        $previousStatut = $tache->getStatut();

        $form = $this->createForm(TacheType::class, $tache);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($tache->getStatut() !== $previousStatut) {
                $tache->setPosition($this->taches->nextPosition($tache->getProjet(), $tache->getStatut()));
            }
            $tache->touch();
            $this->em->flush();
            $this->logChanges('tache.update', $tache->getId(), $before, $tache->snapshot());
            $this->addFlash('success', 'Carte mise à jour.');

            return $this->redirectToRoute('app_projet_show', ['id' => $tache->getProjet()->getId()]);
        }

        return $this->render('projet/tache_form.html.twig', ['form' => $form, 'tache' => $tache, 'projet' => $tache->getProjet(), 'isNew' => false]);
    }

    /** Déplace une carte du tableau d'un projet (appel de la page, en glisser-déposer). */
    #[IsGranted(Permission::PROJET_EDIT)]
    #[Route('/taches/{id}/deplacer', name: 'app_tache_move', methods: 'POST', requirements: ['id' => '\d+'])]
    public function moveTask(Request $request, int $id): JsonResponse
    {
        $tache = $this->taskOr404($id);
        [$statut, $index] = $this->readMove($request, array_keys(Tache::STATUTS));

        $from = $tache->getStatutLabel();
        $changed = $tache->getStatut() !== $statut;
        if ($changed) {
            $tache->transition($statut);
        }
        KanbanOrder::place($this->taches->column($tache->getProjet(), $statut), $tache, $index);
        $this->em->flush();

        if ($changed) {
            $this->activity->log('tache.move', ['id' => $id, 'projet' => $tache->getProjet()->getNom(), 'titre' => $tache->getTitre(), 'de' => $from, 'vers' => $tache->getStatutLabel()]);
        }

        return $this->json(['ok' => true, 'statut' => $tache->getStatut(), 'label' => $tache->getStatutLabel(), 'progress' => $tache->getProjet()->getProgress()]);
    }

    #[IsGranted(Permission::PROJET_EDIT)]
    #[Route('/taches/{id}/supprimer', name: 'app_tache_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function deleteTask(Request $request, int $id): Response
    {
        $tache = $this->taskOr404($id);
        $this->checkToken($request, 'tache_'.$id);

        $projetId = $tache->getProjet()->getId();
        $this->activity->log('tache.delete', ['id' => $id] + $tache->snapshot());
        $this->em->remove($tache);
        $this->em->flush();
        $this->addFlash('success', 'Carte supprimée.');

        return $this->redirectToRoute('app_projet_show', ['id' => $projetId]);
    }

    /**
     * Lit la destination d'un déplacement : colonne et rang.
     *
     * @param list<string> $allowed
     *
     * @return array{0: string, 1: int}
     */
    private function readMove(Request $request, array $allowed): array
    {
        if (!$this->isCsrfTokenValid('kanban', (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $data = json_decode($request->getContent(), true);
        $statut = \is_array($data) && \is_string($data['statut'] ?? null) ? $data['statut'] : '';
        if (!\in_array($statut, $allowed, true)) {
            throw $this->createNotFoundException('Colonne inconnue.');
        }

        return [$statut, max(0, \is_int($data['index'] ?? null) ? $data['index'] : 0)];
    }

    private function logChanges(string $action, ?int $id, array $before, array $after): void
    {
        $changed = array_keys(array_filter($after, static fn ($value, string $key): bool => $before[$key] !== $value, \ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            $this->activity->log($action, ['id' => $id, 'avant' => array_intersect_key($before, array_flip($changed)), 'apres' => array_intersect_key($after, array_flip($changed))]);
        }
    }

    private function findOr404(int $id): Projet
    {
        return $this->projets->findActive($id) ?? throw $this->createNotFoundException('Projet introuvable.');
    }

    private function taskOr404(int $id): Tache
    {
        return $this->taches->findActive($id) ?? throw $this->createNotFoundException('Carte introuvable.');
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
