<?php

namespace App\Controller;

use App\Dto\AbsenceFilter;
use App\Entity\Absence;
use App\Entity\Personnel;
use App\Entity\User;
use App\Form\AbsenceType;
use App\Repository\AbsenceRepository;
use App\Repository\PersonnelRepository;
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
 * Congés et absences. Chacun demande pour soi (« absence.create ») ; un gestionnaire (« absence.manage ») saisit pour tous,
 * approuve ou refuse. La suppression est logique.
 */
#[Route('/absences')]
class AbsenceController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AbsenceRepository $absences,
        private readonly PersonnelRepository $personnel,
        private readonly ActivityLogger $activity,
    ) {
    }

    #[IsGranted(Permission::ABSENCE_VIEW)]
    #[Route('', name: 'app_absences', methods: 'GET')]
    public function index(Request $request): Response
    {
        $filter = AbsenceFilter::fromRequest($request);
        [$rows, $total] = $this->absences->paginate($filter, self::PER_PAGE);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($filter->page > $pages) {
            $filter->page = $pages;
            [$rows] = $this->absences->paginate($filter, self::PER_PAGE);
        }

        $mine = $this->ownPersonnel();
        $year = (int) date('Y');

        return $this->render('absence/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $filter->page,
            'pages' => $pages,
            'filter' => $filter,
            'people' => $this->personnel->findInPost(),
            'dashboard' => $this->absences->dashboard(),
            'mine' => $mine,
            'myDays' => $mine ? $this->absences->daysTaken($mine, $year) : null,
            'year' => $year,
        ]);
    }

    /** Calendrier d'équipe : une ligne par personne absente dans le mois, une colonne par jour. */
    #[IsGranted(Permission::ABSENCE_VIEW)]
    #[Route('/calendrier', name: 'app_absence_calendar', methods: 'GET')]
    public function calendar(Request $request): Response
    {
        $requested = (string) $request->query->get('mois');
        $first = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested)
            ? new \DateTimeImmutable($requested.'-01')
            : new \DateTimeImmutable('first day of this month midnight');
        $last = $first->modify('last day of this month');
        $today = new \DateTimeImmutable('today');

        $days = [];
        for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
            $days[] = ['n' => (int) $day->format('j'), 'date' => $day, 'weekend' => (int) $day->format('N') >= 6, 'today' => $day == $today];
        }

        $rows = [];
        foreach ($this->absences->inRange($first, $last) as $absence) {
            $id = $absence->getPersonnel()->getId();
            $rows[$id] ??= ['person' => $absence->getPersonnel(), 'cells' => []];
            foreach ($days as $day) {
                if ($absence->covers($day['date'])) {
                    $rows[$id]['cells'][$day['n']] = $absence;
                }
            }
        }

        return $this->render('absence/calendar.html.twig', [
            'month' => $first,
            'previous' => $first->modify('-1 month')->format('Y-m'),
            'next' => $first->modify('+1 month')->format('Y-m'),
            'days' => $days,
            'rows' => array_values($rows),
            'dashboard' => $this->absences->dashboard(),
        ]);
    }

    #[IsGranted(Permission::ABSENCE_CREATE)]
    #[Route('/nouvelle', name: 'app_absence_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $choices = $this->choices(null);
        if (!$choices) {
            $this->addFlash('error', 'Votre compte n\'est rattaché à aucune fiche du personnel : demandez à un administrateur de faire le lien avant de poser une absence.');

            return $this->redirectToRoute('app_absences');
        }

        $manager = $this->isGranted(Permission::ABSENCE_MANAGE);
        $user = $this->getUser();
        $absence = (new Absence())->setPersonnel(1 === \count($choices) ? $choices[0] : null)
            ->setDateDebut(new \DateTimeImmutable('today'))->setDateFin(new \DateTimeImmutable('today'));
        if ($user instanceof User) {
            $absence->setCreatedBy($user);
        }

        $form = $this->createForm(AbsenceType::class, $absence, ['personnel_choices' => $choices]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Un gestionnaire déclare une absence déjà acceptée ; les autres envoient une demande à valider.
            if ($manager) {
                $absence->decide(Absence::APPROUVEE, $user instanceof User ? $user : null);
            }
            $this->em->persist($absence);
            $this->em->flush();
            $this->activity->log('absence.create', ['id' => $absence->getId()] + $absence->snapshot());
            $this->addFlash('success', $manager ? 'Absence enregistrée.' : 'Demande envoyée : elle sera examinée par la direction.');

            return $this->redirectToRoute('app_absence_show', ['id' => $absence->getId()]);
        }

        return $this->render('absence/form.html.twig', ['form' => $form, 'absence' => $absence, 'isNew' => true]);
    }

    #[IsGranted(Permission::ABSENCE_VIEW)]
    #[Route('/{id}', name: 'app_absence_show', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $absence = $this->findOr404($id);
        $personnel = $absence->getPersonnel();

        return $this->render('absence/show.html.twig', [
            'absence' => $absence,
            'canSeePrivate' => $this->isOwner($absence) || $this->isGranted(Permission::ABSENCE_MANAGE),
            'canEdit' => $this->canEdit($absence),
            'canCancel' => $absence->isCancellable() && ($this->isOwner($absence) || $this->isGranted(Permission::ABSENCE_MANAGE)),
            'daysThisYear' => $personnel ? $this->absences->daysTaken($personnel, (int) $absence->getDateDebut()?->format('Y')) : 0,
        ]);
    }

    #[IsGranted(Permission::ABSENCE_CREATE)]
    #[Route('/{id}/modifier', name: 'app_absence_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $absence = $this->findOr404($id);
        if (!$this->canEdit($absence)) {
            throw $this->createAccessDeniedException('Cette absence ne peut plus être modifiée.');
        }
        $before = $absence->snapshot();

        $form = $this->createForm(AbsenceType::class, $absence, ['personnel_choices' => $this->choices($absence)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $absence->touch();
            $this->em->flush();
            $after = $absence->snapshot();
            $changed = array_keys(array_filter($after, static fn ($value, string $key): bool => $before[$key] !== $value, \ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                $this->activity->log('absence.update', [
                    'id' => $absence->getId(),
                    'personne' => $absence->getPersonnel()?->getFullName(),
                    'avant' => array_intersect_key($before, array_flip($changed)),
                    'apres' => array_intersect_key($after, array_flip($changed)),
                ]);
            }
            $this->addFlash('success', 'Absence mise à jour.');

            return $this->redirectToRoute('app_absence_show', ['id' => $absence->getId()]);
        }

        return $this->render('absence/form.html.twig', ['form' => $form, 'absence' => $absence, 'isNew' => false]);
    }

    /** Décision d'un gestionnaire sur une demande en attente : approuver, ou refuser avec une raison. */
    #[IsGranted(Permission::ABSENCE_MANAGE)]
    #[Route('/{id}/decision', name: 'app_absence_decision', methods: 'POST', requirements: ['id' => '\d+'])]
    public function decision(Request $request, int $id, ValidatorInterface $validator): Response
    {
        $absence = $this->findOr404($id);
        $this->checkToken($request, $id);

        if (!$absence->isPending()) {
            $this->addFlash('error', 'Cette demande a déjà été traitée.');

            return $this->redirectToRoute('app_absence_show', ['id' => $id]);
        }

        $approve = 'approuver' === $request->request->get('decision');
        if (!$approve && 'refuser' !== $request->request->get('decision')) {
            throw $this->createNotFoundException('Décision inconnue.');
        }

        $user = $this->getUser();
        $absence->setDecision((string) $request->request->get('note'))->decide($approve ? Absence::APPROUVEE : Absence::REFUSEE, $user instanceof User ? $user : null);

        if (\count($violations = $validator->validate($absence)) > 0) {
            $this->em->refresh($absence);
            $this->addFlash('error', $violations[0]->getMessage());

            return $this->redirectToRoute('app_absence_show', ['id' => $id]);
        }

        $this->em->flush();
        $this->activity->log('absence.decision', ['id' => $id, 'personne' => $absence->getPersonnel()?->getFullName(), 'du' => $absence->getDateDebut()?->format('d/m/Y'), 'au' => $absence->getDateFin()?->format('d/m/Y'), 'decision' => $absence->getStatutLabel(), 'note' => $absence->getDecision()]);
        $this->addFlash('success', $approve ? 'Absence approuvée.' : 'Demande refusée.');

        return $this->redirectToRoute('app_absence_show', ['id' => $id]);
    }

    /** L'intéressé(e) ou un gestionnaire annule une absence en attente ou approuvée qui n'est pas entièrement passée. */
    #[IsGranted(Permission::ABSENCE_VIEW)]
    #[Route('/{id}/annuler', name: 'app_absence_cancel', methods: 'POST', requirements: ['id' => '\d+'])]
    public function cancel(Request $request, int $id): Response
    {
        $absence = $this->findOr404($id);
        $this->checkToken($request, $id);
        if (!$this->isOwner($absence) && !$this->isGranted(Permission::ABSENCE_MANAGE)) {
            throw $this->createAccessDeniedException('Seul(e) l\'intéressé(e) ou la direction peut annuler cette absence.');
        }
        if (!$absence->isCancellable()) {
            $this->addFlash('error', 'Cette absence ne peut plus être annulée.');

            return $this->redirectToRoute('app_absence_show', ['id' => $id]);
        }

        $user = $this->getUser();
        $absence->decide(Absence::ANNULEE, $user instanceof User ? $user : null);
        $this->em->flush();
        $this->activity->log('absence.cancel', ['id' => $id] + $absence->snapshot());
        $this->addFlash('success', 'Absence annulée.');

        return $this->redirectToRoute('app_absence_show', ['id' => $id]);
    }

    #[IsGranted(Permission::ABSENCE_DELETE)]
    #[Route('/{id}/supprimer', name: 'app_absence_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $absence = $this->findOr404($id);
        $this->checkToken($request, $id);

        $user = $this->getUser();
        $absence->softDelete($user instanceof User ? $user : null);
        $this->em->flush();
        $this->activity->log('absence.delete', ['id' => $id] + $absence->snapshot());
        $this->addFlash('success', 'Absence supprimée.');

        return $this->redirectToRoute('app_absences');
    }

    /** La fiche du personnel rattachée au compte connecté, si elle existe. */
    private function ownPersonnel(): ?Personnel
    {
        $user = $this->getUser();

        return $user instanceof User ? $this->personnel->findOneBy(['user' => $user, 'deletedAt' => null]) : null;
    }

    private function isOwner(Absence $absence): bool
    {
        $user = $this->getUser();

        return $user instanceof User && $absence->getPersonnel()?->getUser() === $user;
    }

    /**
     * Les personnes que l'on peut choisir : tout le personnel en poste pour un gestionnaire, sinon sa propre fiche.
     *
     * @return list<Personnel>
     */
    private function choices(?Absence $absence): array
    {
        if ($this->isGranted(Permission::ABSENCE_MANAGE)) {
            $choices = $this->personnel->findInPost();
            if ($absence?->getPersonnel() && !\in_array($absence->getPersonnel(), $choices, true)) {
                $choices[] = $absence->getPersonnel();
            }

            return $choices;
        }

        $own = $this->ownPersonnel();

        return $own && $own->isActive() ? [$own] : [];
    }

    /** Un gestionnaire modifie tout ce qui est encore actif ; l'intéressé(e) modifie sa demande tant qu'elle est en attente. */
    private function canEdit(Absence $absence): bool
    {
        if (!$absence->isPending() && !$absence->isApproved()) {
            return false;
        }

        return $this->isGranted(Permission::ABSENCE_MANAGE) || ($this->isOwner($absence) && $absence->isPending());
    }

    private function findOr404(int $id): Absence
    {
        return $this->absences->findActive($id) ?? throw $this->createNotFoundException('Absence introuvable.');
    }

    private function checkToken(Request $request, int $id): void
    {
        if (!$this->isCsrfTokenValid('absence_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
