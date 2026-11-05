<?php

namespace App\Service;

use App\Entity\LinkTool;
use App\Entity\User;
use App\Repository\LinkToolRepository;
use App\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Liste des outils affichés sur la page d'accueil (tuiles) et dans le menu latéral : les outils de l'application,
 * puis les outils de type lien créés dans les réglages du site.
 */
class ToolCatalog
{
    /**
     * Catégories du menu latéral, dans l'ordre d'affichage. L'administration est à part : ses liens (utilisateurs, rôles…)
     * ne sont pas des outils et suivent leurs propres droits.
     */
    public const CATEGORIES = [
        'rh_finance' => 'RH et finance',
        'materiel' => 'Matériel',
        'strategie' => 'Stratégie',
        'autres' => 'Autres outils',
    ];

    /** @var list<LinkTool>|null les liens actifs, lus une seule fois par requête */
    private ?array $links = null;

    public function __construct(
        private readonly AuthorizationCheckerInterface $auth,
        private readonly Security $security,
        private readonly LinkToolRepository $linkTools,
    ) {
    }

    /**
     * Un outil soumis à une permission n'apparaît que pour ceux qui l'ont ; un lien, que pour les rôles choisis (et l'admin).
     *
     * @return list<array{title: string, description: string, route: string, category: string, icon: string, color: string, badge: string, match: list<string>, external?: bool, permission?: string, params?: array<string, int>}>
     */
    public function granted(): array
    {
        $tools = array_filter($this->all(), fn (array $tool): bool => !isset($tool['permission']) || $this->auth->isGranted($tool['permission']));
        $links = array_map($this->linkTile(...), array_filter($this->activeLinks(), $this->canOpen(...)));

        return array_values([...$tools, ...$links]);
    }

    /** Un lien actif est ouvert par l'admin et par les utilisateurs dont le rôle a été coché. */
    public function canOpen(LinkTool $link): bool
    {
        $user = $this->security->getUser();
        if (!$link->isActive() || !$user instanceof User) {
            return false;
        }

        return $user->isAdmin() || \in_array($user->getRole()?->getCode(), $link->getRoleCodes(), true);
    }

    /**
     * Les outils accessibles regroupés par catégorie. Comme les outils dépendent des permissions du rôle,
     * une catégorie n'apparaît que si le rôle donne accès à l'un de ses outils.
     *
     * @return list<array{key: string, label: string, tools: list<array<string, mixed>>}>
     */
    public function groups(): array
    {
        $granted = $this->granted();
        $groups = [];
        foreach (self::CATEGORIES as $key => $label) {
            $tools = array_values(array_filter($granted, static fn (array $tool): bool => $tool['category'] === $key));
            if ($tools) {
                $groups[] = ['key' => $key, 'label' => $label, 'tools' => $tools];
            }
        }

        return $groups;
    }

    /** @return list<LinkTool> */
    private function activeLinks(): array
    {
        return $this->links ??= array_values(array_filter($this->linkTools->findAllOrdered(), static fn (LinkTool $link): bool => $link->isActive()));
    }

    /** La tuile d'un lien : elle passe par la passerelle /liens/{id}, qui vérifie les droits et trace l'ouverture. */
    private function linkTile(LinkTool $link): array
    {
        return [
            'title' => $link->getTitle(),
            'description' => $link->getDescription() ?? '',
            'route' => 'app_lien',
            'params' => ['id' => $link->getId()],
            'category' => \array_key_exists($link->getCategory(), self::CATEGORIES) ? $link->getCategory() : 'autres',
            'match' => [],
            'icon' => $link->getIcon(),
            'color' => 'blue',
            'badge' => $link->getBadge() ?? 'Lien',
            'external' => true,
        ];
    }

    /** @return list<array{title: string, description: string, route: string, category: string, icon: string, color: string, badge: string, match: list<string>, external?: bool, permission?: string}> */
    public function all(): array
    {
        return [
            [
                'title' => 'Correcteur média',
                'description' => 'Relecture éditoriale des posts et visuels : orthographe, registre AFP, cohérence et vérifications factuelles.',
                'route' => 'app_correcteur',
                'category' => 'strategie',
                'match' => ['app_correcteur'],
                'icon' => 'pen',
                'color' => 'mint',
                'badge' => 'IA',
                'permission' => Permission::CORRECTEUR_USE,
            ],
            [
                'title' => 'EspoCRM',
                'description' => 'Suivi des contacts, comptes, opportunités et activités commerciales de PROCOM.',
                'route' => 'app_espocrm',
                'category' => 'strategie',
                'match' => ['app_espocrm'],
                'icon' => 'handshake',
                'color' => 'blue',
                'badge' => 'CRM',
                'external' => true,
                'permission' => Permission::ESPOCRM_USE,
            ],
            [
                'title' => 'Suivi des encaissements',
                'description' => 'Registre des paiements clients avec le détail de la TVA et de l\'AIB, calculés automatiquement.',
                'route' => 'app_encaissements',
                'category' => 'rh_finance',
                'match' => ['app_encaissement', 'app_client'],
                'icon' => 'cash',
                'color' => 'amber',
                'badge' => 'Compta',
                'permission' => Permission::ENCAISSEMENT_VIEW,
            ],
            [
                'title' => 'Factures et devis',
                'description' => 'Devis et factures clients : numérotation, échéances, reste à encaisser et relances des impayés.',
                'route' => 'app_factures',
                'category' => 'rh_finance',
                'match' => ['app_facture'],
                'icon' => 'invoice',
                'color' => 'blue',
                'badge' => 'Ventes',
                'permission' => Permission::FACTURE_VIEW,
            ],
            [
                'title' => 'Projets et missions',
                'description' => 'Projets en tableau Kanban avec leurs tâches, calendrier des missions sur le terrain et bilan de fin de période.',
                'route' => 'app_projets',
                'category' => 'strategie',
                'match' => ['app_projet', 'app_tache', 'app_mission'],
                'icon' => 'kanban',
                'color' => 'blue',
                'badge' => 'Production',
                'permission' => Permission::PROJET_VIEW,
            ],
            [
                'title' => 'Calendrier éditorial',
                'description' => 'Planification des publications par canal et par client, de la rédaction à la mise en ligne, avec relecture et validation.',
                'route' => 'app_calendrier',
                'category' => 'strategie',
                'match' => ['app_calendrier', 'app_publication'],
                'icon' => 'megaphone',
                'color' => 'violet',
                'badge' => 'Contenu',
                'permission' => Permission::CALENDRIER_VIEW,
            ],
            [
                'title' => 'Dépenses et décaissements',
                'description' => 'Dépenses des fournisseurs, restes à payer, TVA déductible et solde mensuel face aux encaissements.',
                'route' => 'app_depenses',
                'category' => 'rh_finance',
                'match' => ['app_depense'],
                'icon' => 'wallet',
                'color' => 'amber',
                'badge' => 'Compta',
                'permission' => Permission::DEPENSE_VIEW,
            ],
            [
                'title' => 'Pannes et demandes',
                'description' => 'Signaler une panne sur un équipement ou demander du matériel, et suivre le traitement jusqu\'à la résolution.',
                'route' => 'app_tickets',
                'category' => 'materiel',
                'match' => ['app_ticket'],
                'icon' => 'wrench',
                'color' => 'rose',
                'badge' => 'Support',
                'permission' => Permission::TICKET_VIEW,
            ],
            [
                'title' => 'Congés et absences',
                'description' => 'Poser un congé, faire valider les demandes et voir qui est absent dans le calendrier d\'équipe.',
                'route' => 'app_absences',
                'category' => 'rh_finance',
                'match' => ['app_absence'],
                'icon' => 'calendar',
                'color' => 'mint',
                'badge' => 'RH',
                'permission' => Permission::ABSENCE_VIEW,
            ],
            [
                'title' => 'Gestion du personnel',
                'description' => 'Fiches du personnel : poste, contrat, dates d\'entrée et de départ, et matériel confié.',
                'route' => 'app_personnel',
                'category' => 'rh_finance',
                'match' => ['app_personnel'],
                'icon' => 'users',
                'color' => 'violet',
                'badge' => 'RH',
                'permission' => Permission::PERSONNEL_VIEW,
            ],
            [
                'title' => 'Gestion du matériel',
                'description' => 'Inventaire des équipements, remise et restitution au personnel, avec historique des affectations.',
                'route' => 'app_materiel',
                'category' => 'materiel',
                'match' => ['app_materiel'],
                'icon' => 'laptop',
                'color' => 'rose',
                'badge' => 'Parc',
                'permission' => Permission::MATERIEL_VIEW,
            ],
        ];
    }
}
