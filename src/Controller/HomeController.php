<?php

namespace App\Controller;

use App\Dto\MaterielFilter;
use App\Entity\ActivityLog;
use App\Entity\Correction;
use App\Repository\AbsenceRepository;
use App\Repository\DepenseRepository;
use App\Repository\DocumentRepository;
use App\Repository\EncaissementRepository;
use App\Repository\MaterielRepository;
use App\Repository\MissionRepository;
use App\Repository\PersonnelRepository;
use App\Repository\ProjetRepository;
use App\Repository\PublicationRepository;
use App\Repository\TicketRepository;
use App\Security\Permission;
use App\Service\ToolCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(
        ToolCatalog $catalog,
        EntityManagerInterface $em,
        EncaissementRepository $encaissements,
        PersonnelRepository $personnel,
        MaterielRepository $materiel,
        DocumentRepository $documents,
        TicketRepository $tickets,
        AbsenceRepository $absences,
        PublicationRepository $publications,
        DepenseRepository $depenses,
        ProjetRepository $projets,
        MissionRepository $missions,
    ): Response {
        return $this->render('home/index.html.twig', [
            'tools' => $catalog->granted(),
            'stats' => $this->stats($em, $encaissements, $personnel, $materiel, $documents, $tickets, $absences, $publications, $depenses, $projets, $missions),
            'activity' => $this->isGranted(Permission::ACTIVITY_VIEW)
                ? $em->getRepository(ActivityLog::class)->findBy([], ['createdAt' => 'DESC'], 6)
                : null,
        ]);
    }

    /**
     * Les chiffres clés des outils auxquels l'utilisateur a accès.
     *
     * @return list<array{label: string, value: string, unit?: string, caption: string, route: string}>
     */
    private function stats(EntityManagerInterface $em, EncaissementRepository $encaissements, PersonnelRepository $personnel, MaterielRepository $materiel, DocumentRepository $documents, TicketRepository $tickets, AbsenceRepository $absences, PublicationRepository $publications, DepenseRepository $depenses, ProjetRepository $projets, MissionRepository $missions): array
    {
        $stats = [];

        if ($this->isGranted(Permission::ENCAISSEMENT_VIEW)) {
            $month = $encaissements->monthlySummary(new \DateTimeImmutable('first day of this month midnight'), null)[0] ?? null;
            $n = (int) ($month['n'] ?? 0);
            $stats[] = [
                'label' => 'Encaissé ce mois-ci',
                'value' => (string) ($month['ttc'] ?? 0),
                'unit' => 'fcfa',
                'caption' => $n.' encaissement'.($n > 1 ? 's' : '').' TTC',
                'route' => 'app_encaissements',
            ];
        }

        if ($this->isGranted(Permission::FACTURE_VIEW)) {
            $board = $documents->dashboard();
            $stats[] = [
                'label' => 'Reste à encaisser',
                'value' => (string) $board['receivable'],
                'unit' => 'fcfa',
                'caption' => $board['overdueCount'] > 0
                    ? $board['overdueCount'].' facture'.($board['overdueCount'] > 1 ? 's' : '').' en retard'
                    : $board['openInvoices'].' facture'.($board['openInvoices'] > 1 ? 's' : '').' ouverte'.($board['openInvoices'] > 1 ? 's' : ''),
                'route' => 'app_factures',
            ];
        }

        if ($this->isGranted(Permission::PROJET_VIEW)) {
            $board = $projets->dashboard();
            $stats[] = [
                'label' => 'Projets en cours',
                'value' => (string) $board['inProgress'],
                'caption' => $board['lateTasks'] > 0 ? $board['lateTasks'].' tâche'.($board['lateTasks'] > 1 ? 's' : '').' en retard' : $board['openTasks'].' tâche'.($board['openTasks'] > 1 ? 's' : '').' ouverte'.($board['openTasks'] > 1 ? 's' : ''),
                'route' => 'app_projets',
            ];
            $field = $missions->dashboard();
            $stats[] = [
                'label' => 'Missions à clôturer',
                'value' => (string) $field['toClose'],
                'caption' => $field['thisWeek'].' sur le terrain cette semaine',
                'route' => 'app_missions',
            ];
        }

        if ($this->isGranted(Permission::CALENDRIER_VIEW)) {
            $board = $publications->dashboard();
            $stats[] = [
                'label' => 'Publications à valider',
                'value' => (string) $board['toReview'],
                'caption' => $board['late'] > 0 ? $board['late'].' en retard' : $board['thisWeek'].' prévue'.($board['thisWeek'] > 1 ? 's' : '').' cette semaine',
                'route' => 'app_calendrier',
            ];
        }

        if ($this->isGranted(Permission::DEPENSE_VIEW)) {
            $board = $depenses->dashboard();
            $stats[] = [
                'label' => 'Dépenses à payer',
                'value' => (string) $board['unpaid'],
                'unit' => 'fcfa',
                'caption' => $board['overdueCount'] > 0
                    ? $board['overdueCount'].' en retard de paiement'
                    : $board['unpaidCount'].' dépense'.($board['unpaidCount'] > 1 ? 's' : '').' non payée'.($board['unpaidCount'] > 1 ? 's' : ''),
                'route' => 'app_depenses',
            ];
        }

        if ($this->isGranted(Permission::TICKET_VIEW)) {
            $board = $tickets->dashboard();
            $todo = $board['open'] + $board['inProgress'];
            $stats[] = [
                'label' => 'Pannes et demandes à traiter',
                'value' => (string) $todo,
                'caption' => $board['urgent'] > 0 ? $board['urgent'].' urgent'.($board['urgent'] > 1 ? 's' : '') : $board['open'].' ouvert'.($board['open'] > 1 ? 's' : ''),
                'route' => 'app_tickets',
            ];
        }

        if ($this->isGranted(Permission::ABSENCE_VIEW)) {
            $board = $absences->dashboard();
            $away = \count($board['absentToday']);
            $stats[] = [
                'label' => "Absents aujourd'hui",
                'value' => (string) $away,
                'caption' => $this->isGranted(Permission::ABSENCE_MANAGE) && $board['pending'] > 0
                    ? $board['pending'].' demande'.($board['pending'] > 1 ? 's' : '').' à traiter'
                    : ($away > 0 ? 'voir le calendrier' : "toute l'équipe est là"),
                'route' => $this->isGranted(Permission::ABSENCE_MANAGE) && $board['pending'] > 0 ? 'app_absences' : 'app_absence_calendar',
            ];
        }

        if ($this->isGranted(Permission::PERSONNEL_VIEW)) {
            $inPost = \count($personnel->findInPost());
            $stats[] = ['label' => 'Personnel en poste', 'value' => (string) $inPost, 'caption' => 'fiche'.($inPost > 1 ? 's' : '').' active'.($inPost > 1 ? 's' : ''), 'route' => 'app_personnel'];
        }

        if ($this->isGranted(Permission::MATERIEL_VIEW)) {
            $totals = $materiel->totals(new MaterielFilter());
            $toRecover = $materiel->countHeldByDeparted();
            $stats[] = [
                'label' => 'Matériel',
                'value' => (string) $totals['n'],
                'caption' => $toRecover > 0 ? $toRecover.' à récupérer' : $totals['assigned'].' affecté'.($totals['assigned'] > 1 ? 's' : ''),
                'route' => 'app_materiel',
            ];
        }

        if ($this->isGranted(Permission::CORRECTEUR_USE)) {
            $count = (int) $em->createQuery('SELECT COUNT(c.id) FROM '.Correction::class.' c WHERE c.user = :u AND c.createdAt >= :d')
                ->setParameter('u', $this->getUser())->setParameter('d', new \DateTimeImmutable('-30 days'))->getSingleScalarResult();
            $stats[] = ['label' => 'Vos corrections', 'value' => (string) $count, 'caption' => '30 derniers jours', 'route' => 'app_correcteur'];
        }

        return $stats;
    }
}
