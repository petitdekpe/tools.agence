<?php

namespace App\Controller;

use App\Dto\TicketFilter;
use App\Entity\Materiel;
use App\Entity\Ticket;
use App\Entity\User;
use App\Form\TicketType;
use App\Repository\TicketRepository;
use App\Security\Permission;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Pannes et demandes de matériel. Toute personne ayant « ticket.create » signale ou demande ; traiter (statut,
 * résolution) exige « ticket.manage » ; la suppression est logique.
 */
#[Route('/pannes')]
class TicketController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketRepository $tickets,
        private readonly ActivityLogger $activity,
    ) {
    }

    #[IsGranted(Permission::TICKET_VIEW)]
    #[Route('', name: 'app_tickets', methods: 'GET')]
    public function index(Request $request): Response
    {
        $filter = TicketFilter::fromRequest($request);
        [$rows, $total] = $this->tickets->paginate($filter, self::PER_PAGE);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($filter->page > $pages) {
            $filter->page = $pages;
            [$rows] = $this->tickets->paginate($filter, self::PER_PAGE);
        }

        return $this->render('ticket/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $filter->page,
            'pages' => $pages,
            'filter' => $filter,
            'dashboard' => $this->tickets->dashboard(),
        ]);
    }

    #[IsGranted(Permission::TICKET_CREATE)]
    #[Route('/nouveau', name: 'app_ticket_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $type = (string) $request->query->get('type');
        $ticket = new Ticket(isset(Ticket::TYPES[$type]) ? $type : Ticket::TYPE_PANNE);
        $user = $this->getUser();
        if ($user instanceof User) {
            $ticket->setCreatedBy($user);
        }
        if ($materielId = $request->query->getInt('materiel')) {
            $materiel = $this->em->getRepository(Materiel::class)->find($materielId);
            if ($materiel && !$materiel->isDeleted() && !$materiel->isReformed()) {
                $ticket->setMateriel($materiel);
            }
        }

        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $markBroken = $ticket->isPanne() && $form->has('marquerEnPanne') && (bool) $form->get('marquerEnPanne')->getData();
            $flagged = false;
            if ($markBroken && null !== $ticket->getMateriel() && 'panne' !== $ticket->getMateriel()->getEtat()) {
                $ticket->getMateriel()->setEtat('panne')->touch();
                $flagged = true;
            }

            $this->em->persist($ticket);
            $this->em->flush();
            $this->activity->log('ticket.create', ['id' => $ticket->getId()] + $ticket->snapshot() + ['materiel_passe_en_panne' => $flagged]);
            $this->addFlash('success', sprintf('%s %s enregistré%s.', $ticket->getTypeLabel(), $ticket->getReference(), $ticket->isDemande() ? 'e' : ''));

            return $this->redirectToRoute('app_ticket_show', ['id' => $ticket->getId()]);
        }

        return $this->render('ticket/form.html.twig', ['form' => $form, 'ticket' => $ticket, 'isNew' => true]);
    }

    #[IsGranted(Permission::TICKET_VIEW)]
    #[Route('/{id}', name: 'app_ticket_show', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $ticket = $this->findOr404($id);

        return $this->render('ticket/show.html.twig', [
            'ticket' => $ticket,
            'canEdit' => $this->canEdit($ticket),
            'statuts' => Ticket::STATUTS,
        ]);
    }

    #[IsGranted(Permission::TICKET_CREATE)]
    #[Route('/{id}/modifier', name: 'app_ticket_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $ticket = $this->findOr404($id);
        if (!$this->canEdit($ticket)) {
            throw $this->createAccessDeniedException('Ce ticket ne peut plus être modifié par son auteur.');
        }
        $before = $ticket->snapshot();

        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ticket->touch();
            $this->em->flush();
            $after = $ticket->snapshot();
            $changed = array_keys(array_filter($after, static fn ($value, string $key): bool => $before[$key] !== $value, \ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                $this->activity->log('ticket.update', [
                    'id' => $ticket->getId(),
                    'numero' => $ticket->getReference(),
                    'avant' => array_intersect_key($before, array_flip($changed)),
                    'apres' => array_intersect_key($after, array_flip($changed)),
                ]);
            }
            $this->addFlash('success', $ticket->getTypeLabel().' mis à jour.');

            return $this->redirectToRoute('app_ticket_show', ['id' => $ticket->getId()]);
        }

        return $this->render('ticket/form.html.twig', ['form' => $form, 'ticket' => $ticket, 'isNew' => false]);
    }

    /** Traitement : nouveau statut, note de résolution, et retour de l'équipement à l'état « bon » si la panne est résolue. */
    #[IsGranted(Permission::TICKET_MANAGE)]
    #[Route('/{id}/statut', name: 'app_ticket_status', methods: 'POST', requirements: ['id' => '\d+'])]
    public function status(Request $request, int $id, ValidatorInterface $validator): Response
    {
        $ticket = $this->findOr404($id);
        $this->checkToken($request, $id);

        $statut = (string) $request->request->get('statut');
        if (!isset(Ticket::STATUTS[$statut])) {
            throw $this->createNotFoundException('Statut inconnu.');
        }

        $from = $ticket->getStatutLabel();
        $user = $this->getUser();
        $ticket->setResolution((string) $request->request->get('resolution', $ticket->getResolution()))
            ->transition($statut, $user instanceof User ? $user : null);

        if (\count($violations = $validator->validate($ticket)) > 0) {
            $this->em->refresh($ticket);
            $this->addFlash('error', $violations[0]->getMessage());

            return $this->redirectToRoute('app_ticket_show', ['id' => $id]);
        }

        $restored = false;
        $materiel = $ticket->getMateriel();
        if (Ticket::RESOLU === $statut && $ticket->isPanne() && $materiel && 'panne' === $materiel->getEtat() && $request->request->getBoolean('remettre_en_etat')) {
            $others = array_filter($this->tickets->activeFor($materiel), static fn (Ticket $t): bool => $t->isPanne() && $t->getId() !== $ticket->getId());
            if (!$others) {
                $materiel->setEtat('bon')->touch();
                $restored = true;
            }
        }

        $this->em->flush();
        $this->activity->log('ticket.status', ['id' => $id, 'numero' => $ticket->getReference(), 'de' => $from, 'vers' => $ticket->getStatutLabel(), 'materiel_remis_en_etat' => $restored]);
        $this->addFlash('success', sprintf('%s : %s.', $ticket->getReference(), mb_strtolower($ticket->getStatutLabel())).($restored ? ' L\'équipement est repassé en bon état.' : ''));

        return $this->redirectToRoute('app_ticket_show', ['id' => $id]);
    }

    #[IsGranted(Permission::TICKET_DELETE)]
    #[Route('/{id}/supprimer', name: 'app_ticket_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $ticket = $this->findOr404($id);
        $this->checkToken($request, $id);

        $user = $this->getUser();
        $ticket->softDelete($user instanceof User ? $user : null);
        $this->em->flush();
        $this->activity->log('ticket.delete', ['id' => $id] + $ticket->snapshot());
        $this->addFlash('success', $ticket->getTypeLabel().' '.$ticket->getReference().' supprimé'.($ticket->isDemande() ? 'e' : '').'.');

        return $this->redirectToRoute('app_tickets', ['type' => $ticket->getType()]);
    }

    /** Un gestionnaire modifie tout ; l'auteur modifie son ticket tant qu'il n'est pas pris en charge. */
    private function canEdit(Ticket $ticket): bool
    {
        if ($this->isGranted(Permission::TICKET_MANAGE)) {
            return true;
        }

        return $ticket->getCreatedBy() === $this->getUser() && Ticket::OUVERT === $ticket->getStatut();
    }

    private function findOr404(int $id): Ticket
    {
        return $this->tickets->findActive($id) ?? throw $this->createNotFoundException('Ticket introuvable.');
    }

    private function checkToken(Request $request, int $id): void
    {
        if (!$this->isCsrfTokenValid('ticket_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
