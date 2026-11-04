<?php

namespace App\Security;

use App\Entity\User;

/**
 * Catalogue des permissions et leurs valeurs par défaut pour les rôles d'origine.
 *
 * Une permission s'utilise comme n'importe quel attribut de sécurité : #[IsGranted('materiel.edit')]
 * ou is_granted('materiel.edit') dans Twig. Le rôle admin les a toutes, sans exception possible ;
 * les autres rôles se règlent depuis Administration > Rôles.
 *
 * Gérer les utilisateurs, les rôles et les réglages du site reste réservé à ROLE_ADMIN et n'est pas délégable :
 * qui pourrait créer un rôle ou changer un rôle d'utilisateur pourrait s'attribuer tous les droits.
 */
final class Permission
{
    public const CORRECTEUR_USE = 'correcteur.use';
    public const ESPOCRM_USE = 'espocrm.use';
    public const ENCAISSEMENT_VIEW = 'encaissement.view';
    public const ENCAISSEMENT_EDIT = 'encaissement.edit';
    public const ENCAISSEMENT_EXPORT = 'encaissement.export';
    public const ENCAISSEMENT_DELETE = 'encaissement.delete';
    public const FACTURE_VIEW = 'facture.view';
    public const FACTURE_EDIT = 'facture.edit';
    public const FACTURE_DELETE = 'facture.delete';
    public const PROJET_VIEW = 'projet.view';
    public const PROJET_EDIT = 'projet.edit';
    public const PROJET_DELETE = 'projet.delete';
    public const MISSION_PLAN = 'mission.plan';
    public const CALENDRIER_VIEW = 'calendrier.view';
    public const CALENDRIER_EDIT = 'calendrier.edit';
    public const CALENDRIER_VALIDATE = 'calendrier.validate';
    public const CALENDRIER_DELETE = 'calendrier.delete';
    public const DEPENSE_VIEW = 'depense.view';
    public const DEPENSE_EDIT = 'depense.edit';
    public const DEPENSE_DELETE = 'depense.delete';
    public const TICKET_VIEW = 'ticket.view';
    public const TICKET_CREATE = 'ticket.create';
    public const TICKET_MANAGE = 'ticket.manage';
    public const TICKET_DELETE = 'ticket.delete';
    public const ABSENCE_VIEW = 'absence.view';
    public const ABSENCE_CREATE = 'absence.create';
    public const ABSENCE_MANAGE = 'absence.manage';
    public const ABSENCE_DELETE = 'absence.delete';
    public const PERSONNEL_VIEW = 'personnel.view';
    public const PERSONNEL_EDIT = 'personnel.edit';
    public const PERSONNEL_DELETE = 'personnel.delete';
    public const MATERIEL_VIEW = 'materiel.view';
    public const MATERIEL_EDIT = 'materiel.edit';
    public const MATERIEL_ASSIGN = 'materiel.assign';
    public const MATERIEL_EXPORT = 'materiel.export';
    public const MATERIEL_DELETE = 'materiel.delete';
    public const ADMIN_ACCESS = 'admin.access';
    public const USERS_VIEW = 'users.view';
    public const ACTIVITY_VIEW = 'activity.view';

    /** Permissions par outil, avec leur libellé. La première de chaque groupe est l'accès : toutes les autres l'exigent. */
    public const CATALOG = [
        'Correcteur média' => [
            self::CORRECTEUR_USE => 'Utiliser le correcteur média',
        ],
        'EspoCRM' => [
            self::ESPOCRM_USE => 'Ouvrir EspoCRM',
        ],
        'Encaissements' => [
            self::ENCAISSEMENT_VIEW => 'Consulter les encaissements, clients et synthèses',
            self::ENCAISSEMENT_EDIT => 'Saisir et modifier des encaissements et des clients',
            self::ENCAISSEMENT_EXPORT => 'Exporter les encaissements (Excel, PDF)',
            self::ENCAISSEMENT_DELETE => 'Supprimer un encaissement',
        ],
        'Factures et devis' => [
            self::FACTURE_VIEW => 'Consulter les factures et les devis',
            self::FACTURE_EDIT => 'Créer, modifier, émettre et facturer un devis',
            self::FACTURE_DELETE => 'Supprimer une facture ou un devis',
        ],
        'Projets et missions' => [
            self::PROJET_VIEW => 'Consulter les projets, les tâches et les missions',
            self::PROJET_EDIT => 'Créer et modifier les projets, déplacer les cartes',
            self::PROJET_DELETE => 'Supprimer un projet',
            self::MISSION_PLAN => 'Programmer les missions et faire le bilan de période (responsable de programmation)',
        ],
        'Calendrier éditorial' => [
            self::CALENDRIER_VIEW => 'Consulter le calendrier éditorial',
            self::CALENDRIER_EDIT => 'Planifier, modifier et faire avancer les publications',
            self::CALENDRIER_VALIDATE => 'Valider les publications (statut « validé »)',
            self::CALENDRIER_DELETE => 'Supprimer une publication',
        ],
        'Dépenses et décaissements' => [
            self::DEPENSE_VIEW => 'Consulter les dépenses et la synthèse',
            self::DEPENSE_EDIT => 'Saisir, modifier et marquer payées les dépenses',
            self::DEPENSE_DELETE => 'Supprimer une dépense',
        ],
        'Pannes et demandes de matériel' => [
            self::TICKET_VIEW => 'Consulter les pannes et les demandes',
            self::TICKET_CREATE => 'Signaler une panne ou demander du matériel',
            self::TICKET_MANAGE => 'Traiter les tickets (statut, résolution)',
            self::TICKET_DELETE => 'Supprimer un ticket',
        ],
        'Congés et absences' => [
            self::ABSENCE_VIEW => 'Consulter les absences et le calendrier d\'équipe',
            self::ABSENCE_CREATE => 'Poser ses propres congés et absences',
            self::ABSENCE_MANAGE => 'Saisir pour tous, approuver ou refuser les demandes',
            self::ABSENCE_DELETE => 'Supprimer une absence',
        ],
        'Personnel' => [
            self::PERSONNEL_VIEW => 'Consulter les fiches du personnel',
            self::PERSONNEL_EDIT => 'Créer, modifier et marquer un départ',
            self::PERSONNEL_DELETE => 'Supprimer une fiche',
        ],
        'Matériel' => [
            self::MATERIEL_VIEW => 'Consulter l\'inventaire',
            self::MATERIEL_EDIT => 'Créer et modifier du matériel',
            self::MATERIEL_ASSIGN => 'Remettre et récupérer du matériel',
            self::MATERIEL_EXPORT => 'Exporter l\'inventaire (Excel, PDF)',
            self::MATERIEL_DELETE => 'Supprimer du matériel',
        ],
        'Administration' => [
            self::ADMIN_ACCESS => 'Accéder à l\'espace d\'administration (tableau de bord)',
            self::USERS_VIEW => 'Consulter la liste des utilisateurs',
            self::ACTIVITY_VIEW => 'Consulter le journal d\'activité',
        ],
    ];

    private const AGENT = [
        self::CORRECTEUR_USE, self::ESPOCRM_USE,
        self::ENCAISSEMENT_VIEW, self::ENCAISSEMENT_EDIT, self::ENCAISSEMENT_EXPORT,
        self::FACTURE_VIEW, self::FACTURE_EDIT,
        self::PROJET_VIEW, self::PROJET_EDIT,
        self::CALENDRIER_VIEW, self::CALENDRIER_EDIT,
        self::TICKET_VIEW, self::TICKET_CREATE,
        self::ABSENCE_VIEW, self::ABSENCE_CREATE,
        self::MATERIEL_VIEW, self::MATERIEL_EDIT, self::MATERIEL_ASSIGN, self::MATERIEL_EXPORT,
    ];

    /** Ce que chaque équipe peut faire par elle-même : poser ses congés, signaler une panne, suivre les projets et le calendrier. */
    private const BASE = [
        self::ABSENCE_VIEW, self::ABSENCE_CREATE,
        self::TICKET_VIEW, self::TICKET_CREATE,
        self::PROJET_VIEW,
        self::CALENDRIER_VIEW,
    ];

    /**
     * Valeurs d'origine des rôles système, reprises tant qu'aucun réglage n'a été enregistré
     * (y compris pour une permission ajoutée plus tard). Un rôle créé démarre sans aucune permission.
     */
    public const DEFAULTS = [
        User::ROLE_AGENT => self::AGENT,
        // Les équipes : chacune reçoit le socle commun, puis les outils de son métier.
        User::ROLE_ADMINISTRATION => [
            ...self::BASE,
            self::ADMIN_ACCESS, self::USERS_VIEW, self::ACTIVITY_VIEW,
            self::PERSONNEL_VIEW, self::PERSONNEL_EDIT,
            self::ABSENCE_MANAGE,
            self::TICKET_MANAGE,
            self::MATERIEL_VIEW,
            self::ESPOCRM_USE,
        ],
        User::ROLE_RH_FINANCE => [
            ...self::BASE,
            self::PERSONNEL_VIEW, self::PERSONNEL_EDIT,
            self::ABSENCE_MANAGE,
            self::ENCAISSEMENT_VIEW, self::ENCAISSEMENT_EDIT, self::ENCAISSEMENT_EXPORT,
            self::FACTURE_VIEW, self::FACTURE_EDIT,
            self::DEPENSE_VIEW, self::DEPENSE_EDIT,
            self::MATERIEL_VIEW,
        ],
        User::ROLE_MATERIEL => [
            ...self::BASE,
            self::MATERIEL_VIEW, self::MATERIEL_EDIT, self::MATERIEL_ASSIGN, self::MATERIEL_EXPORT,
            self::TICKET_MANAGE,
            self::PERSONNEL_VIEW,
        ],
        User::ROLE_STRATEGIE => [
            ...self::BASE,
            self::CORRECTEUR_USE, self::ESPOCRM_USE,
            self::CALENDRIER_EDIT, self::CALENDRIER_VALIDATE,
            self::PROJET_EDIT, self::MISSION_PLAN,
            self::FACTURE_VIEW,
            self::PERSONNEL_VIEW,
            self::MATERIEL_VIEW,
        ],
        User::ROLE_DIRECTION => [
            ...self::AGENT,
            self::PERSONNEL_VIEW, self::PERSONNEL_EDIT,
            self::TICKET_MANAGE, self::ABSENCE_MANAGE,
            self::CALENDRIER_VALIDATE, self::DEPENSE_VIEW, self::DEPENSE_EDIT, self::MISSION_PLAN,
            self::ADMIN_ACCESS, self::USERS_VIEW, self::ACTIVITY_VIEW,
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::CATALOG)));
    }

    public static function exists(string $permission): bool
    {
        return \in_array($permission, self::all(), true);
    }

    public static function label(string $permission): string
    {
        foreach (self::CATALOG as $group) {
            if (isset($group[$permission])) {
                return $group[$permission];
            }
        }

        return $permission;
    }

    /** Permission d'accès du groupe auquel appartient celle-ci (toute autre permission du groupe l'exige). */
    public static function viewOf(string $permission): string
    {
        foreach (self::CATALOG as $group) {
            if (isset($group[$permission])) {
                return array_key_first($group);
            }
        }

        return $permission;
    }
}
