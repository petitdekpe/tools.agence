<?php

namespace App\Dto;

use App\Service\Choices;
use Symfony\Component\HttpFoundation\Request;

/** Recherche, filtres, tri et page de la liste du personnel, lus dans la query string. */
final class PersonnelFilter
{
    public const SORTS = ['nom', 'poste', 'service', 'contrat', 'entree', 'statut'];
    public const STATUSES = ['actif' => 'En poste', 'parti' => 'Parti'];

    public string $q = '';
    public ?string $statut = null;
    public string $service = '';
    public ?string $contrat = null;
    public string $sort = 'nom';
    public string $dir = 'asc';
    public int $page = 1;

    public static function fromRequest(Request $request): self
    {
        $filter = new self();
        $filter->q = mb_substr(trim((string) $request->query->get('q')), 0, 100);
        $statut = (string) $request->query->get('statut');
        $filter->statut = isset(self::STATUSES[$statut]) ? $statut : null;
        $filter->service = mb_substr(trim((string) $request->query->get('service')), 0, 120);
        $contrat = (string) $request->query->get('contrat');
        $filter->contrat = Choices::has('personnel_contract', $contrat) ? $contrat : null;
        $sort = (string) $request->query->get('sort');
        $filter->sort = \in_array($sort, self::SORTS, true) ? $sort : 'nom';
        $filter->dir = 'desc' === $request->query->get('dir') ? 'desc' : 'asc';
        $filter->page = max(1, $request->query->getInt('page', 1));

        return $filter;
    }

    /** Paramètres d'URL des filtres, pour les liens de tri et de pagination. */
    public function toQuery(bool $withSort = true): array
    {
        $query = array_filter([
            'q' => '' !== $this->q ? $this->q : null,
            'statut' => $this->statut,
            'service' => '' !== $this->service ? $this->service : null,
            'contrat' => $this->contrat,
        ], static fn ($v) => null !== $v);

        return $withSort ? $query + ['sort' => $this->sort, 'dir' => $this->dir] : $query;
    }

    public function isActive(): bool
    {
        return [] !== $this->toQuery(false);
    }
}
