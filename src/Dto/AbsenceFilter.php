<?php

namespace App\Dto;

use App\Entity\Absence;
use Symfony\Component\HttpFoundation\Request;

/** Filtres, tri et page de la liste des absences, lus dans la query string. */
final class AbsenceFilter
{
    public const SORTS = ['debut', 'personne', 'type', 'jours', 'statut'];

    public ?string $etat = null;
    public ?string $type = null;
    public ?int $personnel = null;
    public ?int $annee = null;
    public string $sort = 'debut';
    public string $dir = 'desc';
    public int $page = 1;

    public static function fromRequest(Request $request): self
    {
        $filter = new self();
        $etat = (string) $request->query->get('etat');
        $filter->etat = isset(Absence::STATUTS[$etat]) ? $etat : null;
        $type = (string) $request->query->get('type');
        $filter->type = isset(Absence::TYPES[$type]) ? $type : null;
        $filter->personnel = self::int($request, 'personnel') ?: null;
        $year = self::int($request, 'annee');
        $filter->annee = $year >= 2000 && $year <= 2100 ? $year : null;
        $sort = (string) $request->query->get('sort');
        $filter->sort = \in_array($sort, self::SORTS, true) ? $sort : 'debut';
        $filter->dir = 'asc' === $request->query->get('dir') ? 'asc' : 'desc';
        $filter->page = max(1, self::int($request, 'page', 1));

        return $filter;
    }

    /** Paramètres d'URL des filtres, pour les liens de tri et de pagination. */
    public function toQuery(bool $withSort = true): array
    {
        $query = array_filter([
            'etat' => $this->etat,
            'type' => $this->type,
            'personnel' => $this->personnel,
            'annee' => $this->annee,
        ], static fn ($v) => null !== $v);

        return $withSort ? $query + ['sort' => $this->sort, 'dir' => $this->dir] : $query;
    }

    public function isActive(): bool
    {
        return [] !== $this->toQuery(false);
    }

    /** Entier d'un paramètre d'URL ; toute valeur qui n'est pas un entier donne 0 au lieu d'une erreur 400. */
    private static function int(Request $request, string $key, int $default = 0): int
    {
        $value = $request->query->get($key);

        return \is_string($value) && ctype_digit($value) ? (int) $value : $default;
    }
}
