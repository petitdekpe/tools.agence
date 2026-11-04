<?php

namespace App\Controller;

use App\Dto\PublicationFilter;
use App\Entity\Publication;
use App\Entity\User;
use App\Form\PublicationType;
use App\Repository\ClientRepository;
use App\Repository\PersonnelRepository;
use App\Repository\PublicationRepository;
use App\Security\Permission;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Calendrier éditorial. Planifier et faire avancer une publication exige « calendrier.edit » ; passer à « validé »
 * exige « calendrier.validate » ; la suppression est logique.
 */
#[Route('/calendrier')]
class PublicationController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PublicationRepository $publications,
        private readonly ActivityLogger $activity,
    ) {
    }

    /** Grille du mois : sept colonnes à partir du lundi, une case par jour. */
    #[IsGranted(Permission::CALENDRIER_VIEW)]
    #[Route('', name: 'app_calendrier', methods: 'GET')]
    public function month(Request $request, ClientRepository $clients, PersonnelRepository $personnel): Response
    {
        $filter = PublicationFilter::fromRequest($request);
        $requested = (string) $request->query->get('mois');
        $first = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested)
            ? new \DateTimeImmutable($requested.'-01')
            : new \DateTimeImmutable('first day of this month midnight');
        $last = $first->modify('last day of this month');
        $gridStart = $first->modify('monday this week');
        $gridEnd = $last->modify('sunday this week');
        $today = new \DateTimeImmutable('today');

        $byDay = [];
        foreach ($this->publications->between($gridStart, $gridEnd, $filter) as $publication) {
            $byDay[$publication->getDatePublication()->format('Y-m-d')][] = $publication;
        }

        $weeks = [];
        for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')) {
            $weeks[(int) floor($day->diff($gridStart)->days / 7)][] = [
                'date' => $day,
                'inMonth' => $day->format('Y-m') === $first->format('Y-m'),
                'today' => $day == $today,
                'weekend' => (int) $day->format('N') >= 6,
                'items' => $byDay[$day->format('Y-m-d')] ?? [],
            ];
        }

        return $this->render('calendrier/month.html.twig', [
            'month' => $first,
            'previous' => $first->modify('-1 month')->format('Y-m'),
            'next' => $first->modify('+1 month')->format('Y-m'),
            'weeks' => array_values($weeks),
            'filter' => $filter,
            'query' => $filter->toCalendarQuery(),
            'dashboard' => $this->publications->dashboard(),
            'clients' => $clients->findAllByName(),
            'people' => $personnel->findInPost(),
        ]);
    }

    #[IsGranted(Permission::CALENDRIER_VIEW)]
    #[Route('/liste', name: 'app_publications', methods: 'GET')]
    public function list(Request $request, ClientRepository $clients, PersonnelRepository $personnel): Response
    {
        $filter = PublicationFilter::fromRequest($request);
        [$rows, $total] = $this->publications->paginate($filter, self::PER_PAGE);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($filter->page > $pages) {
            $filter->page = $pages;
            [$rows] = $this->publications->paginate($filter, self::PER_PAGE);
        }

        return $this->render('calendrier/list.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $filter->page,
            'pages' => $pages,
            'filter' => $filter,
            'dashboard' => $this->publications->dashboard(),
            'clients' => $clients->findAllByName(),
            'people' => $personnel->findInPost(),
        ]);
    }

    #[IsGranted(Permission::CALENDRIER_EDIT)]
    #[Route('/nouvelle', name: 'app_publication_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $publication = new Publication();
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('date'));
        $publication->setDatePublication($date ?: new \DateTimeImmutable('today'));
        $user = $this->getUser();
        if ($user instanceof User) {
            $publication->setCreatedBy($user);
        }

        $form = $this->createForm(PublicationType::class, $publication, ['can_validate' => $this->isGranted(Permission::CALENDRIER_VALIDATE)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($publication);
            $this->em->flush();
            $this->activity->log('publication.create', ['id' => $publication->getId()] + $publication->snapshot());
            $this->addFlash('success', 'Publication planifiée.');

            return $this->redirectToRoute('app_publication_show', ['id' => $publication->getId()]);
        }

        return $this->render('calendrier/form.html.twig', ['form' => $form, 'publication' => $publication, 'isNew' => true]);
    }

    #[IsGranted(Permission::CALENDRIER_VIEW)]
    #[Route('/{id}', name: 'app_publication_show', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        return $this->render('calendrier/show.html.twig', [
            'publication' => $this->findOr404($id),
            'statuts' => $this->allowedStatuts(),
        ]);
    }

    #[IsGranted(Permission::CALENDRIER_EDIT)]
    #[Route('/{id}/modifier', name: 'app_publication_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $publication = $this->findOr404($id);
        $before = $publication->snapshot();

        $form = $this->createForm(PublicationType::class, $publication, ['can_validate' => $this->isGranted(Permission::CALENDRIER_VALIDATE)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $publication->touch();
            $this->em->flush();
            $after = $publication->snapshot();
            $changed = array_keys(array_filter($after, static fn ($value, string $key): bool => $before[$key] !== $value, \ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                $this->activity->log('publication.update', [
                    'id' => $publication->getId(),
                    'titre' => $publication->getTitre(),
                    'avant' => array_intersect_key($before, array_flip($changed)),
                    'apres' => array_intersect_key($after, array_flip($changed)),
                ]);
            }
            $this->addFlash('success', 'Publication mise à jour.');

            return $this->redirectToRoute('app_publication_show', ['id' => $publication->getId()]);
        }

        return $this->render('calendrier/form.html.twig', ['form' => $form, 'publication' => $publication, 'isNew' => false]);
    }

    /** Fait avancer la publication dans le circuit ; « validé » est réservé à ceux qui valident. */
    #[IsGranted(Permission::CALENDRIER_EDIT)]
    #[Route('/{id}/statut', name: 'app_publication_status', methods: 'POST', requirements: ['id' => '\d+'])]
    public function status(Request $request, int $id): Response
    {
        $publication = $this->findOr404($id);
        $this->checkToken($request, $id);

        $statut = (string) $request->request->get('statut');
        if (!isset(Publication::STATUTS[$statut])) {
            throw $this->createNotFoundException('Statut inconnu.');
        }
        if (Publication::VALIDE === $statut && !$this->isGranted(Permission::CALENDRIER_VALIDATE)) {
            throw $this->createAccessDeniedException('Seule la validation peut passer une publication à « validé ».');
        }

        if ($publication->getStatut() !== $statut) {
            $from = $publication->getStatutLabel();
            $publication->setStatut($statut)->touch();
            $this->em->flush();
            $this->activity->log('publication.status', ['id' => $id, 'titre' => $publication->getTitre(), 'de' => $from, 'vers' => $publication->getStatutLabel()]);
            $this->addFlash('success', sprintf('« %s » : %s.', $publication->getTitre(), mb_strtolower($publication->getStatutLabel())));
        }

        return $this->redirectToRoute('app_publication_show', ['id' => $id]);
    }

    #[IsGranted(Permission::CALENDRIER_DELETE)]
    #[Route('/{id}/supprimer', name: 'app_publication_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $publication = $this->findOr404($id);
        $this->checkToken($request, $id);

        $user = $this->getUser();
        $publication->softDelete($user instanceof User ? $user : null);
        $this->em->flush();
        $this->activity->log('publication.delete', ['id' => $id] + $publication->snapshot());
        $this->addFlash('success', 'Publication supprimée.');

        return $this->redirectToRoute('app_calendrier', ['mois' => $publication->getDatePublication()?->format('Y-m')]);
    }

    /** @return array<string, string> les statuts que l'utilisateur peut attribuer */
    private function allowedStatuts(): array
    {
        return $this->isGranted(Permission::CALENDRIER_VALIDATE) ? Publication::STATUTS : array_diff_key(Publication::STATUTS, [Publication::VALIDE => 1]);
    }

    private function findOr404(int $id): Publication
    {
        return $this->publications->findActive($id) ?? throw $this->createNotFoundException('Publication introuvable.');
    }

    private function checkToken(Request $request, int $id): void
    {
        if (!$this->isCsrfTokenValid('publication_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
