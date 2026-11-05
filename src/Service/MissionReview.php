<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\Personnel;
use App\Entity\Projet;
use App\Entity\Tache;
use App\Repository\MissionRepository;
use App\Repository\TacheRepository;
use App\Service\Choices;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le point de fin de période : ce qui était prévu sur le terrain, ce qui a été fait, ce qui reste à clôturer,
 * qui a été mobilisé, et l'avancement des projets et des tâches sur la même période.
 * Les missions comptent dans la période où elles commencent.
 */
class MissionReview
{
    public function __construct(
        private readonly MissionRepository $missions,
        private readonly TacheRepository $tasks,
        private readonly EntityManagerInterface $em,
        private readonly Choices $choices,
    ) {
    }

    /**
     * @return array{
     *     from: \DateTimeImmutable, to: \DateTimeImmutable, total: int, done: int, cancelled: int, postponed: int, upcoming: int, toClose: int,
     *     rate: ?int, fieldDays: int, byStatus: array<string, int>, byResult: array<string, int>, noResult: int,
     *     byPerson: list<array{person: Personnel, missions: int, done: int, days: int, lead: int}>,
     *     byProject: list<array{label: string, projet: ?Projet, missions: int, done: int}>,
     *     missions: list<Mission>, tasksClosed: list<Tache>, tasksOverdue: list<Tache>, projectsClosed: list<Projet>
     * }
     */
    public function build(\DateTimeImmutable $from, \DateTimeImmutable $to, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $missions = $this->missions->startingBetween($from, $to);

        $byStatus = array_fill_keys(array_keys(Mission::STATUTS), 0);
        $byResult = array_fill_keys(array_keys($this->choices->labels('mission_resultat')), 0);
        $noResult = $fieldDays = $toClose = $upcoming = 0;
        $people = [];
        $projects = [];

        foreach ($missions as $mission) {
            ++$byStatus[$mission->getStatut()];
            $done = Mission::REALISEE === $mission->getStatut();
            if ($mission->isToClose($today)) {
                ++$toClose;
            } elseif ($mission->isOpen()) {
                ++$upcoming;
            }
            if ($done) {
                null === $mission->getResultat() ? ++$noResult : ++$byResult[$mission->getResultat()];
            }

            $participants = $mission->getParticipants();
            if ($done) {
                $fieldDays += $mission->getDays() * \count($participants);
            }
            foreach ($participants as $person) {
                $row = &$people[$person->getId()];
                $row ??= ['person' => $person, 'missions' => 0, 'done' => 0, 'days' => 0, 'lead' => 0];
                ++$row['missions'];
                $row['done'] += $done ? 1 : 0;
                $row['days'] += $done ? $mission->getDays() : 0;
                $row['lead'] += $mission->getResponsable() === $person ? 1 : 0;
                unset($row);
            }

            $key = $mission->getProjet()?->getId() ?? 0;
            $projects[$key] ??= ['label' => $mission->getProjet()?->getNom() ?? 'Sans projet', 'projet' => $mission->getProjet(), 'missions' => 0, 'done' => 0];
            ++$projects[$key]['missions'];
            $projects[$key]['done'] += $done ? 1 : 0;
        }

        // Taux de réalisation : réalisées sur les missions qui devaient avoir lieu (hors annulées, reportées et à venir).
        $due = $byStatus[Mission::REALISEE] + $toClose;
        $rate = $due > 0 ? (int) round($byStatus[Mission::REALISEE] / $due * 100) : null;

        usort($people, static fn (array $a, array $b): int => [$b['days'], $b['missions']] <=> [$a['days'], $a['missions']]);
        usort($projects, static fn (array $a, array $b): int => $b['missions'] <=> $a['missions']);

        return [
            'from' => $from,
            'to' => $to,
            'total' => \count($missions),
            'done' => $byStatus[Mission::REALISEE],
            'cancelled' => $byStatus[Mission::ANNULEE],
            'postponed' => $byStatus[Mission::REPORTEE],
            'upcoming' => $upcoming,
            'toClose' => $toClose,
            'rate' => $rate,
            'fieldDays' => $fieldDays,
            'byStatus' => $byStatus,
            'byResult' => $byResult,
            'noResult' => $noResult,
            'byPerson' => array_values($people),
            'byProject' => array_values($projects),
            'missions' => $missions,
            'tasksClosed' => $this->tasks->closedBetween($from, $to),
            'tasksOverdue' => $this->tasks->overdue($today),
            'projectsClosed' => $this->projectsClosed($from, $to),
        ];
    }

    /** @return list<Projet> */
    private function projectsClosed(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->em->createQuery('SELECT p FROM '.Projet::class.' p WHERE p.deletedAt IS NULL AND p.statut = :s AND p.closedAt >= :from AND p.closedAt < :to ORDER BY p.closedAt ASC')
            ->setParameter('s', Projet::TERMINE)->setParameter('from', $from)->setParameter('to', $to->modify('+1 day'))->getResult();
    }
}
