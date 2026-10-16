<?php

namespace App\Service;

/** Liste des outils affichés sous forme de cards sur la page d'accueil. */
class ToolCatalog
{
    /** @return list<array{title: string, description: string, route: string, icon: string, color: string, badge: string}> */
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
            ],
        ];
    }
}
