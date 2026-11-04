<?php

namespace App\Service;

use App\Entity\Mission;
use App\Repository\AbsenceRepository;
use App\Repository\MissionRepository;

/**
 * Détecte les conflits d'une mission : une personne mobilisée qui est déjà sur une autre mission ouverte
 * ou absente (absence en attente ou approuvée) pendant la période. Les conflits préviennent, ils ne bloquent pas.
 */
class MissionPlanner
{
    public function __construct(private readonly MissionRepository $missions, private readonly AbsenceRepository $absences)
    {
    }

    /** @return list<string> un message par conflit, prêt à être affiché */
    public function conflicts(Mission $mission): array
    {
        $from = $mission->getDateDebut();
        $to = $mission->getDateFin();
        if (!$from || !$to || $to < $from || !$mission->isOpen()) {
            return [];
        }

        $messages = [];
        foreach ($mission->getParticipants() as $person) {
            foreach ($this->missions->overlappingFor($person, $from, $to, $mission->getId()) as $other) {
                $messages[] = sprintf('%s est déjà sur « %s » du %s au %s.', $person->getFullName(), $other->getTitre(), $other->getDateDebut()->format('d/m/Y'), $other->getDateFin()->format('d/m/Y'));
            }
            foreach ($this->absences->findForPersonIn($person, $from, $to) as $absence) {
                $messages[] = sprintf(
                    '%s est absent(e) du %s au %s (%s, %s).',
                    $person->getFullName(),
                    $absence->getDateDebut()->format('d/m/Y'),
                    $absence->getDateFin()->format('d/m/Y'),
                    mb_strtolower($absence->getTypeLabel()),
                    mb_strtolower($absence->getStatutLabel()),
                );
            }
        }

        return $messages;
    }
}
