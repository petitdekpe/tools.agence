<?php

namespace App\Service;

use App\Security\Permission;

/** Liste des outils affichés sous forme de cards sur la page d'accueil. */
class ToolCatalog
{
    /** @return list<array{title: string, description: string, route: string, icon: string, color: string, badge: string, external?: bool, permission?: string}> */
    public function all(): array
    {
        return [
            [
                'title' => 'Correcteur média',
                'description' => 'Relecture éditoriale des posts et visuels : orthographe, registre AFP, cohérence et vérifications factuelles.',
                'route' => 'app_correcteur',
                'icon' => '✍️',
                'color' => 'mint',
                'badge' => 'IA',
                'permission' => Permission::CORRECTEUR_USE,
            ],
            [
                'title' => 'EspoCRM',
                'description' => 'Suivi des contacts, comptes, opportunités et activités commerciales de PROCOM.',
                'route' => 'app_espocrm',
                'icon' => '🤝',
                'color' => 'blue',
                'badge' => 'CRM',
                'external' => true,
                'permission' => Permission::ESPOCRM_USE,
            ],
            [
                'title' => 'Suivi des encaissements',
                'description' => 'Registre des paiements clients avec le détail de la TVA et de l\'AIB, calculés automatiquement.',
                'route' => 'app_encaissements',
                'icon' => '💰',
                'color' => 'amber',
                'badge' => 'Compta',
                'permission' => Permission::ENCAISSEMENT_VIEW,
            ],
            [
                'title' => 'Gestion du personnel',
                'description' => 'Fiches du personnel : poste, contrat, dates d\'entrée et de départ, et matériel confié.',
                'route' => 'app_personnel',
                'icon' => '👥',
                'color' => 'violet',
                'badge' => 'RH',
                'permission' => Permission::PERSONNEL_VIEW,
            ],
            [
                'title' => 'Gestion du matériel',
                'description' => 'Inventaire des équipements, remise et restitution au personnel, avec historique des affectations.',
                'route' => 'app_materiel',
                'icon' => '💻',
                'color' => 'rose',
                'badge' => 'Parc',
                'permission' => Permission::MATERIEL_VIEW,
            ],
        ];
    }
}
