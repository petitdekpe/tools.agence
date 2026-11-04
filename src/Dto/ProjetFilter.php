<?php

namespace App\Dto;

use App\Entity\Projet;
use Symfony\Component\HttpFoundation\Request;

/** Recherche, filtres, tri et page des projets, lus dans la query string (tableau et liste). */
final class ProjetFilter
{
    public const SORTS = ['nom', 'client', 'echeance', 'statut', 'avancement'];

    public string $q = '';
    public ?int $client = null;
    public ?int $responsable = null;
    public ?string $statut = null;
    public string $sort = 'echeance';
    public string $dir = 'asc';
    public int $page = 1;

    public static function fromRequest(Request $request): self
    {
        $filter = new self();
        $filter->q = mb_substr(trim((string) $request->query->get('q')), 0, 100);
        $filter->client = self::int($request, 'client') ?: null;
        $filter->responsable = self::int($request, 'responsable') ?: null;
        $statut = (string) $request->query->get('statut');
        $filter->statut = isset(Projet::STATUTS[$statut]) || 'ouverts' === $statut ? $statut : null;
        $sort = (string) $request->query->get('sort');
        $filter->sort = \in_array($sort, self::SORTS, true) ? $sort : 'echeance';
        $filter->dir = 'desc' === $request->query->get('dir') ? 'desc' : 'asc';
        $filter->page = max(1, self::int($request, 'page', 1));

        return $filter;
    }

    /** Paramètres d'URL des filtres, pour les liens de tri, de pagination et entre les deux vues. */
    public function toQuery(bool $withSort = true): array
    {
        $query = array_filter([
            'q' => '' !== $this->q ? $this->q : null,
            'client' => $this->client,
            'responsable' => $this->responsable,
            'statut' => $this->statut,
        ], static fn ($v) => null !== $v);

        return $withSort ? $query + ['sort' => $this->sort, 'dir' => $this->dir] : $query;
    }

    public function isActive(): bool
    {
        return [] !== $this->toQuery(false);
    }

    /** Entier d'un paramètre d'URL ; toute valeur qui n'est pas un entier donne le défaut au lieu d'une erreur 400. */
    private static function int(Request $request, string $key, int $default = 0): int
    {
        $value = $request->query->get($key);

        return \is_string($value) && ctype_digit($value) ? (int) $value : $default;
    }
}
