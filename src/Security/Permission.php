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
        self::MATERIEL_VIEW, self::MATERIEL_EDIT, self::MATERIEL_ASSIGN, self::MATERIEL_EXPORT,
    ];

    /**
     * Valeurs d'origine des rôles système, reprises tant qu'aucun réglage n'a été enregistré
     * (y compris pour une permission ajoutée plus tard). Un rôle créé démarre sans aucune permission.
     */
    public const DEFAULTS = [
        User::ROLE_AGENT => self::AGENT,
        User::ROLE_DIRECTION => [
            ...self::AGENT,
            self::PERSONNEL_VIEW, self::PERSONNEL_EDIT,
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
