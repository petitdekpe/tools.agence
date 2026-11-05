<?php

namespace App\Service;

use App\Entity\Affectation;
use App\Entity\Materiel;
use App\Entity\Personnel;
use App\Entity\User;
use App\Service\Choices;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remise et restitution du matériel. Garantit qu'un équipement n'a qu'un détenteur à la fois
 * et que les règles métier tiennent quel que soit l'écran appelant. Les refus sont des \DomainException
 * dont le message est affichable tel quel.
 */
class MaterielAssigner
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Choices $choices)
    {
    }

    public function assign(Materiel $materiel, Personnel $personnel, \DateTimeImmutable $date, ?string $note, ?User $by): Affectation
    {
        if ($date > new \DateTimeImmutable('today')) {
            throw new \DomainException('La date de remise ne peut pas être dans le futur.');
        }

        return $this->transactional(function () use ($materiel, $personnel, $date, $note, $by): Affectation {
            // Verrou sur le matériel : deux remises simultanées ne peuvent pas toutes deux réussir.
            $this->em->lock($materiel, LockMode::PESSIMISTIC_WRITE);

            if ($materiel->isDeleted()) {
                throw new \DomainException('Ce matériel a été supprimé.');
            }
            if ($materiel->isReformed()) {
                throw new \DomainException('Un matériel réformé ne peut pas être remis.');
            }
            if (!$personnel->isActive()) {
                throw new \DomainException(sprintf('%s n\'est plus en poste : le matériel ne peut pas lui être remis.', $personnel->getFullName()));
            }
            if ($this->openCount($materiel) > 0) {
                throw new \DomainException('Ce matériel est déjà affecté. Il doit d\'abord être restitué.');
            }

            $affectation = (new Affectation($materiel, $personnel, $date))->setNote($note)->setAssignedBy($by);
            $materiel->getAffectations()->add($affectation);
            $materiel->touch();
            $this->em->persist($affectation);
            $this->em->flush();

            return $affectation;
        });
    }

    /** @param string|null $etat état constaté au retour ; null laisse l'état du matériel inchangé */
    public function giveBack(Materiel $materiel, \DateTimeImmutable $date, ?string $etat, ?string $note, ?User $by): Affectation
    {
        if (null !== $etat && !$this->choices->exists('materiel_etat', $etat)) {
            throw new \DomainException('État invalide.');
        }

        return $this->transactional(function () use ($materiel, $date, $etat, $note, $by): Affectation {
            $this->em->lock($materiel, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($materiel);

            $affectation = $materiel->getCurrentAffectation() ?? throw new \DomainException('Ce matériel n\'est affecté à personne.');
            if ($date < $affectation->getAssignedAt()) {
                throw new \DomainException('La restitution est antérieure à la remise ('.$affectation->getAssignedAt()->format('d/m/Y').').');
            }
            if ($date > new \DateTimeImmutable('today')) {
                throw new \DomainException('La date de restitution ne peut pas être dans le futur.');
            }

            $note = null === $note || '' === trim($note) ? null : trim($note);
            $affectation->close($date, $etat, $note, $by);
            if (null !== $etat) {
                $materiel->setEtat($etat);
            }
            $materiel->touch();
            $this->em->flush();

            return $affectation;
        });
    }

    /** Comme wrapInTransaction, mais sans fermer l'EntityManager quand une règle métier refuse l'opération. */
    private function transactional(callable $work): Affectation
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    private function openCount(Materiel $materiel): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(a.id) FROM '.Affectation::class.' a WHERE a.materiel = :m AND a.returnedAt IS NULL')
            ->setParameter('m', $materiel)->getSingleScalarResult();
    }
}
