<?php

namespace App\Controller;

use App\Dto\MissionFilter;
use App\Entity\Mission;
use App\Entity\Projet;
use App\Entity\User;
use App\Form\MissionType;
use App\Repository\ClientRepository;
use App\Repository\MissionRepository;
use App\Repository\PersonnelRepository;
use App\Repository\ProjetRepository;
use App\Security\Permission;
use App\Service\ActivityLogger;
use App\Service\Choices;
use App\Service\MissionPlanner;
use App\Service\MissionReview;
use App\Service\Notifications;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Missions sur le terrain. Les consulter exige « projet.view » ; les programmer (créer, modifier, changer le statut), les supprimer
 * et faire le point de fin de période est le rôle du responsable de programmation (« mission.plan »). Le chef de mission et son équipe
 * rédigent le compte rendu de leur mission. La suppression est logique.
 */
#[Route('/missions')]
class MissionController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionRepository $missions,
        private readonly ActivityLogger $activity,
        private readonly Choices $choices,
        private readonly Notifications $notifications,
        private readonly MissionPlanner $planner,
    ) {
    }

    /** Calendrier du mois : sept colonnes à partir du lundi ; une mission figure chaque jour où elle a lieu. */
    #[IsGranted(Permission::PROJET_VIEW)]
    #[Route('', name: 'app_missions', methods: 'GET')]
    public function month(Request $request, ClientRepository $clients, PersonnelRepository $personnel, ProjetRepository $projets): Response
    {
        $filter = MissionFilter::fromRequest($request);
        $requested = (string) $request->query->get('mois');
        $first = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested)
            ? new \DateTimeImmutable($requested.'-01')
            : new \DateTimeImmutable('first day of this month midnight');
        $last = $first->modify('last day of this month');
        $gridStart = $first->modify('monday this week');
        $gridEnd = $last->modify('sunday this week');
        $today = new \DateTimeImmutable('today');
        $found = $this->missions->between($gridStart, $gridEnd, $filter);

        $weeks = [];
        for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')) {
            $weeks[(int) floor($day->diff($gridStart)->days / 7)][] = [
                'date' => $day,
                'inMonth' => $day->format('Y-m') === $first->format('Y-m'),
                'today' => $day == $today,
                'weekend' => (int) $day->format('N') >= 6,
                'items' => array_values(array_filter($found, static fn (Mission $m): bool => $m->covers($day))),
            ];
        }

        return $this->render('mission/month.html.twig', [
            'month' => $first,
            'previous' => $first->modify('-1 month')->format('Y-m'),
            'next' => $first->modify('+1 month')->format('Y-m'),
            'weeks' => array_values($weeks),
            'filter' => $filter,
            'query' => $filter->toCalendarQuery(),
            'dashboard' => $this->missions->dashboard(),
            'clients' => $clients->findAllByName(),
            'people' => $personnel->findInPost(),
            'projects' => $this->openProjects($projets),
        ]);
    }

    #[IsGranted(Permission::PROJET_VIEW)]
    #[Route('/liste', name: 'app_missions_list', methods: 'GET')]
    public function list(Request $request, ClientRepository $clients, PersonnelRepository $personnel, ProjetRepository $projets): Response
    {
        $filter = MissionFilter::fromRequest($request);
        [$rows, $total] = $this->missions->paginate($filter, self::PER_PAGE);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($filter->page > $pages) {
            $filter->page = $pages;
            [$rows] = $this->missions->paginate($filter, self::PER_PAGE);
        }

        return $this->render('mission/list.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $filter->page,
            'pages' => $pages,
            'filter' => $filter,
            'dashboard' => $this->missions->dashboard(),
            'clients' => $clients->findAllByName(),
            'people' => $personnel->findInPost(),
            'projects' => $this->openProjects($projets),
        ]);
    }

    /** Le point de fin de période, par le responsable de programmation. */
    #[IsGranted(Permission::MISSION_PLAN)]
    #[Route('/bilan', name: 'app_missions_review', methods: 'GET')]
    public function review(Request $request, MissionReview $review): Response
    {
        $today = new \DateTimeImmutable('today');
        $presets = [
            'semaine' => ['Semaine dernière', $today->modify('monday last week'), $today->modify('sunday last week')],
            'courant' => ['Ce mois-ci', $today->modify('first day of this month'), $today],
            'mois' => ['Mois dernier', $today->modify('first day of last month'), $today->modify('last day of last month')],
            'trimestre' => ['Trimestre en cours', $this->quarterStart($today), $today],
        ];

        $preset = (string) $request->query->get('periode');
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('from')) ?: null;
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('to')) ?: null;
        if ($from && $to) {
            $preset = 'perso';
        } elseif (isset($presets[$preset])) {
            [, $from, $to] = $presets[$preset];
        } else {
            $preset = 'mois';
            [, $from, $to] = $presets['mois'];
        }
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return $this->render('mission/review.html.twig', [
            'report' => $review->build($from, $to),
            'presets' => $presets,
            'preset' => $preset,
            'resultats' => $this->choices->labels('mission_resultat'),
            'statuts' => Mission::STATUTS,
        ]);
    }

    #[IsGranted(Permission::MISSION_PLAN)]
    #[Route('/nouvelle', name: 'app_mission_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ProjetRepository $projets): Response
    {
        $mission = new Mission();
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('date')) ?: new \DateTimeImmutable('today');
        $mission->setDateDebut($date)->setDateFin($date);
        if ($projetId = $request->query->getInt('projet')) {
            $projet = $projets->findActive($projetId);
            if ($projet) {
                $mission->setProjet($projet);
            }
        }
        $user = $this->getUser();
        if ($user instanceof User) {
            $mission->setCreatedBy($user);
        }

        $form = $this->createForm(MissionType::class, $mission);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($mission);
            $this->em->flush();
            $this->activity->log('mission.create', ['id' => $mission->getId()] + $mission->snapshot());
            $this->notifications->missionScheduled($mission);
            $this->addFlash('success', 'Mission programmée.');
            $this->warnOfConflicts($mission);

            return $this->redirectToRoute('app_mission_show', ['id' => $mission->getId()]);
        }

        return $this->render('mission/form.html.twig', ['form' => $form, 'mission' => $mission, 'isNew' => true]);
    }

    #[IsGranted(Permission::PROJET_VIEW)]
    #[Route('/{id}', name: 'app_mission_show', methods: 'GET', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $mission = $this->findOr404($id);

        return $this->render('mission/show.html.twig', [
            'mission' => $mission,
            'conflicts' => $this->planner->conflicts($mission),
            'canReport' => $this->canReport($mission),
            'statuts' => Mission::STATUTS,
            'resultats' => $this->choices->labels('mission_resultat'),
        ]);
    }

    #[IsGranted(Permission::MISSION_PLAN)]
    #[Route('/{id}/modifier', name: 'app_mission_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $mission = $this->findOr404($id);
        $before = $mission->snapshot();

        $form = $this->createForm(MissionType::class, $mission);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $mission->touch();
            $this->em->flush();
            $after = $mission->snapshot();
            $changed = array_keys(array_filter($after, static fn ($value, string $key): bool => $before[$key] !== $value, \ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                $this->activity->log('mission.update', [
                    'id' => $mission->getId(),
                    'titre' => $mission->getTitre(),
                    'avant' => array_intersect_key($before, array_flip($changed)),
                    'apres' => array_intersect_key($after, array_flip($changed)),
                ]);
            }
            $this->addFlash('success', 'Mission mise à jour.');
            $this->warnOfConflicts($mission);

            return $this->redirectToRoute('app_mission_show', ['id' => $mission->getId()]);
        }

        return $this->render('mission/form.html.twig', ['form' => $form, 'mission' => $mission, 'isNew' => false]);
    }

    /** Changement de statut par le responsable de programmation : confirmer, reporter, annuler, ou rouvrir. */
    #[IsGranted(Permission::MISSION_PLAN)]
    #[Route('/{id}/statut', name: 'app_mission_status', methods: 'POST', requirements: ['id' => '\d+'])]
    public function status(Request $request, int $id): Response
    {
        $mission = $this->findOr404($id);
        $this->checkToken($request, $id);

        $statut = (string) $request->request->get('statut');
        if (!isset(Mission::STATUTS[$statut]) || Mission::REALISEE === $statut) {
            throw $this->createNotFoundException('Statut inconnu : une mission devient « réalisée » par son compte rendu.');
        }

        if ($mission->getStatut() !== $statut) {
            $from = $mission->getStatutLabel();
            $mission->setStatut($statut)->touch();
            $this->em->flush();
            $this->activity->log('mission.status', ['id' => $id, 'titre' => $mission->getTitre(), 'de' => $from, 'vers' => $mission->getStatutLabel()]);
            $this->notifications->missionStatusChanged($mission, $from);
            $this->addFlash('success', sprintf('« %s » : %s.', $mission->getTitre(), mb_strtolower($mission->getStatutLabel())));
            $this->warnOfConflicts($mission);
        }

        return $this->redirectToRoute('app_mission_show', ['id' => $id]);
    }

    /** Retour de terrain : le chef de mission, son équipe ou le responsable de programmation clôture la mission. */
    #[IsGranted(Permission::PROJET_VIEW)]
    #[Route('/{id}/compte-rendu', name: 'app_mission_report', methods: 'POST', requirements: ['id' => '\d+'])]
    public function report(Request $request, int $id): Response
    {
        $mission = $this->findOr404($id);
        $this->checkToken($request, $id);
        if (!$this->canReport($mission)) {
            throw $this->createAccessDeniedException('Seuls l\'équipe de la mission et le responsable de programmation peuvent la clôturer.');
        }

        $today = new \DateTimeImmutable('today');
        if (Mission::ANNULEE === $mission->getStatut() || $mission->getDateDebut() > $today) {
            $this->addFlash('error', 'Cette mission n\'a pas encore commencé ou a été annulée : elle ne peut pas être clôturée.');

            return $this->redirectToRoute('app_mission_show', ['id' => $id]);
        }

        $resultat = (string) $request->request->get('resultat');
        $compteRendu = trim((string) $request->request->get('compte_rendu'));
        if (!$this->choices->exists('mission_resultat', $resultat)) {
            $this->addFlash('error', 'Indiquez si les objectifs ont été atteints.');

            return $this->redirectToRoute('app_mission_show', ['id' => $id]);
        }
        if (mb_strlen($compteRendu) > 6000) {
            $this->addFlash('error', 'Le compte rendu dépasse 6 000 caractères.');

            return $this->redirectToRoute('app_mission_show', ['id' => $id]);
        }

        $mission->report($resultat, $compteRendu);
        $this->em->flush();
        $this->activity->log('mission.report', ['id' => $id, 'titre' => $mission->getTitre(), 'resultat' => $mission->getResultatLabel()]);
        $this->notifications->missionReported($mission);
        $this->addFlash('success', 'Compte rendu enregistré : la mission est réalisée.');

        return $this->redirectToRoute('app_mission_show', ['id' => $id]);
    }

    #[IsGranted(Permission::MISSION_PLAN)]
    #[Route('/{id}/supprimer', name: 'app_mission_delete', methods: 'POST', requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        $mission = $this->findOr404($id);
        $this->checkToken($request, $id);

        $user = $this->getUser();
        $mission->softDelete($user instanceof User ? $user : null);
        $this->em->flush();
        $this->activity->log('mission.delete', ['id' => $id] + $mission->snapshot());
        $this->addFlash('success', 'Mission supprimée.');

        return $this->redirectToRoute('app_missions', ['mois' => $mission->getDateDebut()?->format('Y-m')]);
    }

    private function canReport(Mission $mission): bool
    {
        $user = $this->getUser();

        return $this->isGranted(Permission::MISSION_PLAN) || ($user instanceof User && $mission->hasParticipant($user));
    }

    /** Prévient (sans bloquer) quand une personne mobilisée est déjà prise ou absente. */
    private function warnOfConflicts(Mission $mission): void
    {
        foreach ($this->planner->conflicts($mission) as $message) {
            $this->addFlash('warning', $message);
        }
    }

    /** @return list<Projet> les projets sur lesquels on peut programmer une mission */
    private function openProjects(ProjetRepository $projets): array
    {
        return $projets->findBy(['deletedAt' => null], ['nom' => 'ASC']);
    }

    private function quarterStart(\DateTimeImmutable $today): \DateTimeImmutable
    {
        $month = (int) $today->format('n');

        return new \DateTimeImmutable($today->format('Y').'-'.str_pad((string) (intdiv($month - 1, 3) * 3 + 1), 2, '0', \STR_PAD_LEFT).'-01');
    }

    private function findOr404(int $id): Mission
    {
        return $this->missions->findActive($id) ?? throw $this->createNotFoundException('Mission introuvable.');
    }

    private function checkToken(Request $request, int $id): void
    {
        if (!$this->isCsrfTokenValid('mission_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
