<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Document;
use App\Entity\Materiel;
use App\Entity\Mission;
use App\Entity\Personnel;
use App\Entity\Projet;
use App\Entity\Publication;
use App\Entity\Tache;
use App\Entity\Ticket;
use App\Entity\User;
use App\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Qui est prévenu, et de quoi, pour chaque événement de l'application. Un seul endroit pour ajuster les destinataires
 * et les textes ; l'envoi lui-même (et le silence sur l'auteur de l'action) est dans Notifier.
 * À appeler une fois l'action enregistrée.
 */
class Notifications
{
    /** Nombre de connexions échouées sur une même adresse, en LOGIN_FAILURE_WINDOW minutes, qui déclenche l'alerte. */
    public const LOGIN_FAILURE_THRESHOLD = 5;
    public const LOGIN_FAILURE_WINDOW = 15;

    public function __construct(private readonly Notifier $notifier, private readonly Security $security)
    {
    }

    // --- Absences ---------------------------------------------------------------------------------------------

    /** Demande à valider : les gestionnaires sont prévenus. Absence déjà acceptée, saisie pour quelqu'un : l'intéressé(e) l'est. */
    public function absenceCreated(Absence $absence): void
    {
        $who = $absence->getPersonnel()?->getFullName() ?? 'Une personne';
        if ($absence->isPending()) {
            $this->notifier->send($this->notifier->holders(Permission::ABSENCE_MANAGE), 'Demande d\'absence de '.$who, [
                sprintf('%s demande : %s.', $who, $this->absenceSummary($absence)),
                'Elle attend votre décision.',
            ], 'app_absence_show', ['id' => $absence->getId()]);

            return;
        }

        $this->notifier->send([$absence->getPersonnel()], 'Une absence a été enregistrée pour vous', [
            sprintf('%s a enregistré pour vous : %s.', $this->actorName(), $this->absenceSummary($absence)),
        ], 'app_absence_show', ['id' => $absence->getId()]);
    }

    public function absenceDecided(Absence $absence): void
    {
        $approved = Absence::APPROUVEE === $absence->getStatut();
        $lines = [sprintf('Votre demande (%s) a été %s par %s.', $this->absenceSummary($absence), $approved ? 'approuvée' : 'refusée', $this->actorName())];
        if ($absence->getDecision()) {
            $lines[] = 'Commentaire : '.$absence->getDecision();
        }
        $this->notifier->send([$absence->getPersonnel()], 'Votre demande d\'absence a été '.($approved ? 'approuvée' : 'refusée'), $lines, 'app_absence_show', ['id' => $absence->getId()]);
    }

    public function absenceCancelled(Absence $absence): void
    {
        $who = $absence->getPersonnel()?->getFullName() ?? 'Une personne';
        $this->notifier->send([$absence->getPersonnel(), ...$this->notifier->holders(Permission::ABSENCE_MANAGE)], 'Absence annulée : '.$who, [
            sprintf('%s a annulé l\'absence de %s : %s.', $this->actorName(), $who, $this->absenceSummary($absence)),
        ], 'app_absence_show', ['id' => $absence->getId()]);
    }

    // --- Tickets ----------------------------------------------------------------------------------------------

    public function ticketCreated(Ticket $ticket): void
    {
        $lines = [sprintf('%s %s : %s.', $ticket->getTypeLabel(), $ticket->getReference(), $ticket->getTitre()), 'Priorité : '.mb_strtolower($ticket->getPrioriteLabel()).'.'];
        if ($materiel = $ticket->getMateriel()) {
            $lines[] = 'Matériel concerné : '.$materiel->getDesignation().'.';
        }
        $lines[] = 'Signalé par '.($ticket->getCreatedBy()?->getFullName() ?? $this->actorName()).'.';

        $this->notifier->send($this->notifier->holders(Permission::TICKET_MANAGE), sprintf('Nouveau ticket %s : %s', $ticket->getReference(), $ticket->getTitre()), $lines, 'app_ticket_show', ['id' => $ticket->getId()]);
    }

    public function ticketStatusChanged(Ticket $ticket, string $from): void
    {
        $lines = [sprintf('%s %s (%s) est passé de « %s » à « %s » (par %s).', $ticket->getTypeLabel(), $ticket->getReference(), $ticket->getTitre(), $from, $ticket->getStatutLabel(), $this->actorName())];
        if ($ticket->getResolution()) {
            $lines[] = 'Résolution : '.$ticket->getResolution();
        }

        $this->notifier->send([$ticket->getCreatedBy(), $ticket->getHandledBy()], sprintf('Ticket %s : %s', $ticket->getReference(), mb_strtolower($ticket->getStatutLabel())), $lines, 'app_ticket_show', ['id' => $ticket->getId()]);
    }

    // --- Missions ---------------------------------------------------------------------------------------------

    public function missionScheduled(Mission $mission): void
    {
        $this->notifier->send($this->missionTeam($mission), 'Mission programmée : '.$mission->getTitre(), [
            sprintf('Vous êtes associé(e) à la mission « %s » : %s.', $mission->getTitre(), $this->missionSummary($mission)),
        ], 'app_mission_show', ['id' => $mission->getId()]);
    }

    public function missionStatusChanged(Mission $mission, string $from): void
    {
        $this->notifier->send($this->missionTeam($mission), sprintf('Mission « %s » : %s', $mission->getTitre(), mb_strtolower($mission->getStatutLabel())), [
            sprintf('La mission « %s » (%s) est passée de « %s » à « %s ».', $mission->getTitre(), $this->missionSummary($mission), $from, $mission->getStatutLabel()),
        ], 'app_mission_show', ['id' => $mission->getId()]);
    }

    public function missionReported(Mission $mission): void
    {
        $this->notifier->send([$mission->getResponsable(), ...$this->notifier->holders(Permission::MISSION_PLAN)], 'Compte rendu de mission : '.$mission->getTitre(), [
            sprintf('%s a clôturé la mission « %s » (%s).', $this->actorName(), $mission->getTitre(), $this->missionSummary($mission)),
            'Objectifs : '.($mission->getResultatLabel() ?? 'non précisés').'.',
        ], 'app_mission_show', ['id' => $mission->getId()]);
    }

    // --- Matériel ---------------------------------------------------------------------------------------------

    public function materielAssigned(Materiel $materiel, Personnel $to, \DateTimeImmutable $date): void
    {
        $this->notifier->send([$to], 'Matériel remis : '.$materiel->getDesignation(), [
            sprintf('« %s » vous a été remis le %s par %s. Vous en êtes désormais responsable.', $materiel->getDesignation(), $date->format('d/m/Y'), $this->actorName()),
            'Signalez toute panne depuis la page Pannes et demandes.',
        ], 'app_materiel_show', ['id' => $materiel->getId()]);
    }

    public function materielReturned(Materiel $materiel, ?Personnel $from, \DateTimeImmutable $date): void
    {
        $this->notifier->send([$from], 'Matériel restitué : '.$materiel->getDesignation(), [
            sprintf('La restitution de « %s » a été enregistrée le %s par %s.', $materiel->getDesignation(), $date->format('d/m/Y'), $this->actorName()),
        ], 'app_materiel_show', ['id' => $materiel->getId()]);
    }

    // --- Projets, tâches --------------------------------------------------------------------------------------

    public function tacheAssigned(Tache $tache): void
    {
        $projet = $tache->getProjet();
        $this->notifier->send([$tache->getAssignee()], 'Nouvelle carte : '.$tache->getTitre(), [
            sprintf('%s vous a confié la carte « %s » du projet « %s ».', $this->actorName(), $tache->getTitre(), $projet->getNom()),
            $tache->getEcheance() ? 'Échéance : '.$tache->getEcheance()->format('d/m/Y').'.' : 'Aucune échéance fixée.',
        ], 'app_projet_show', ['id' => $projet->getId()]);
    }

    public function tacheMoved(Tache $tache, string $from): void
    {
        $projet = $tache->getProjet();
        $this->notifier->send([$tache->getAssignee(), $projet->getResponsable()], sprintf('Carte « %s » : %s', $tache->getTitre(), mb_strtolower($tache->getStatutLabel())), [
            sprintf('%s a déplacé la carte « %s » du projet « %s » de « %s » à « %s ».', $this->actorName(), $tache->getTitre(), $projet->getNom(), $from, $tache->getStatutLabel()),
        ], 'app_projet_show', ['id' => $projet->getId()]);
    }

    public function projetStatusChanged(Projet $projet, string $from): void
    {
        $this->notifier->send([$projet->getResponsable()], sprintf('Projet « %s » : %s', $projet->getNom(), mb_strtolower($projet->getStatutLabel())), [
            sprintf('%s a fait passer le projet « %s » de « %s » à « %s ».', $this->actorName(), $projet->getNom(), $from, $projet->getStatutLabel()),
        ], 'app_projet_show', ['id' => $projet->getId()]);
    }

    // --- Publications -----------------------------------------------------------------------------------------

    /** Le responsable et l'auteur suivent l'avancement ; ceux qui valident sont appelés dès que la publication passe en relecture. */
    public function publicationStatusChanged(Publication $publication, string $from): void
    {
        $recipients = [$publication->getResponsable(), $publication->getCreatedBy()];
        $lines = [sprintf('%s a fait passer la publication « %s » (%s) de « %s » à « %s ».', $this->actorName(), $publication->getTitre(), $publication->getCanalLabel(), $from, $publication->getStatutLabel())];
        if (Publication::RELECTURE === $publication->getStatut()) {
            $recipients = [...$recipients, ...$this->notifier->holders(Permission::CALENDRIER_VALIDATE)];
            $lines[] = 'Elle attend votre relecture et votre validation.';
        }

        $this->notifier->send($recipients, sprintf('Publication « %s » : %s', $publication->getTitre(), mb_strtolower($publication->getStatutLabel())), $lines, 'app_publication_show', ['id' => $publication->getId()]);
    }

    // --- Factures et devis ------------------------------------------------------------------------------------

    /** L'auteur du document et l'équipe qui suit les paiements (permission « depense.edit »). */
    public function documentStatusChanged(Document $document, string $from): void
    {
        $this->notifier->send([$document->getCreatedBy(), ...$this->notifier->holders(Permission::DEPENSE_EDIT)], sprintf('%s %s : %s', $document->getTypeLabel(), $document->getReference(), mb_strtolower($document->getStatutLabel())), [
            sprintf('%s a fait passer %s %s (%s) de « %s » à « %s ».', $this->actorName(), mb_strtolower($document->getTypeLabel()), $document->getReference(), $document->getObjet(), $from, $document->getStatutLabel()),
            $document->getClient() ? 'Client : '.$document->getClient()->getName().'.' : 'Aucun client indiqué.',
        ], 'app_facture_show', ['id' => $document->getId()]);
    }

    // --- Sécurité : alertes aux administrateurs ---------------------------------------------------------------

    public function loginFailuresReached(string $email, int $count, ?string $ip): void
    {
        $this->notifier->send($this->notifier->admins(), 'Alerte : connexions échouées', [
            sprintf('%d tentatives de connexion échouées en %d minutes pour l\'adresse « %s »%s.', $count, self::LOGIN_FAILURE_WINDOW, $email, $ip ? ' (dernière adresse IP : '.$ip.')' : ''),
            'Si ce n\'est pas normal, désactivez le compte ou changez son mot de passe.',
        ], 'app_admin_activity');
    }

    public function twoFactorEnabled(User $user): void
    {
        $this->notifier->send($this->notifier->admins(), 'Authentificateur activé : '.$user->getFullName(), [
            sprintf('%s (%s) vient d\'activer Google Authenticator.', $user->getFullName(), $user->getEmail()),
        ], 'app_admin_users');
    }

    public function accountToggled(User $user): void
    {
        $state = $user->isActive() ? 'réactivé' : 'désactivé';
        $this->notifier->send($this->notifier->admins(), sprintf('Compte %s : %s', $state, $user->getFullName()), [
            sprintf('%s a %s le compte de %s (%s).', $this->actorName(), $user->isActive() ? 'réactivé' : 'désactivé', $user->getFullName(), $user->getEmail()),
        ], 'app_admin_users');
    }

    /** Le changement de rôle est signalé aux administrateurs et à la personne concernée. */
    public function roleChanged(User $user, string $from): void
    {
        $this->notifier->send([$user, ...$this->notifier->admins()], 'Changement de rôle : '.$user->getFullName(), [
            sprintf('%s a changé le rôle de %s (%s) : « %s » devient « %s ».', $this->actorName(), $user->getFullName(), $user->getEmail(), $from, $user->getRoleLabel()),
        ], 'app_admin_users');
    }

    // --- Outils -----------------------------------------------------------------------------------------------

    private function actorName(): string
    {
        $actor = $this->security->getUser();

        return $actor instanceof User ? $actor->getFullName() : 'Un gestionnaire';
    }

    private function absenceSummary(Absence $absence): string
    {
        $from = $absence->getDateDebut();
        $to = $absence->getDateFin();
        $period = $from && $to && $from != $to ? sprintf('du %s au %s', $from->format('d/m/Y'), $to->format('d/m/Y')) : 'le '.($from?->format('d/m/Y') ?? '?');

        return sprintf('%s %s (%d jour%s)', mb_strtolower($absence->getTypeLabel()), $period, $absence->getJours(), $absence->getJours() > 1 ? 's' : '');
    }

    private function missionSummary(Mission $mission): string
    {
        $from = $mission->getDateDebut();
        $to = $mission->getDateFin();
        $period = $from && $to && $from != $to ? sprintf('du %s au %s', $from->format('d/m/Y'), $to->format('d/m/Y')) : 'le '.($from?->format('d/m/Y') ?? '?');

        return trim($period.($mission->getLieu() ? ', '.$mission->getLieu() : ''));
    }

    /** @return list<Personnel|null> le responsable et l'équipe de la mission */
    private function missionTeam(Mission $mission): array
    {
        return [$mission->getResponsable(), ...$mission->getEquipe()->toArray()];
    }
}
