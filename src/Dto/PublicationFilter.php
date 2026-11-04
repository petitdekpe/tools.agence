<?php

namespace App\Dto;

use App\Entity\Publication;
use Symfony\Component\HttpFoundation\Request;

/** Recherche, filtres, tri et page du calendrier éditorial, lus dans la query string (grille mensuelle et liste). */
final class PublicationFilter
{
    public const SORTS = ['date', 'titre', 'canal', 'statut'];

    public string $q = '';
    public ?int $client = null;
    public ?string $canal = null;
    public ?string $statut = null;
    public ?int $responsable = null;
    public ?\DateTimeImmutable $from = null;
    public ?\DateTimeImmutable $to = null;
    public string $sort = 'date';
    public string $dir = 'asc';
    public int $page = 1;

    public static function fromRequest(Request $request): self
    {
        $filter = new self();
        $filter->q = mb_substr(trim((string) $request->query->get('q')), 0, 100);
        $filter->client = self::int($request, 'client') ?: null;
        $canal = (string) $request->query->get('canal');
        $filter->canal = isset(Publication::CANAUX[$canal]) ? $canal : null;
        $statut = (string) $request->query->get('statut');
        $filter->statut = isset(Publication::STATUTS[$statut]) || 'encours' === $statut ? $statut : null;
        $filter->responsable = self::int($request, 'responsable') ?: null;
        $filter->from = self::date($request->query->get('from'));
        $filter->to = self::date($request->query->get('to'));
        $sort = (string) $request->query->get('sort');
        $filter->sort = \in_array($sort, self::SORTS, true) ? $sort : 'date';
        $filter->dir = 'desc' === $request->query->get('dir') ? 'desc' : 'asc';
        $filter->page = max(1, self::int($request, 'page', 1));

        return $filter;
    }

    /** Paramètres d'URL des filtres (hors période et tri), repris par les liens de la grille et de la liste. */
    public function toQuery(bool $withSort = true): array
    {
        $query = array_filter([
            'q' => '' !== $this->q ? $this->q : null,
            'client' => $this->client,
            'canal' => $this->canal,
            'statut' => $this->statut,
            'responsable' => $this->responsable,
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
        ], static fn ($v) => null !== $v);

        return $withSort ? $query + ['sort' => $this->sort, 'dir' => $this->dir] : $query;
    }

    /** Les mêmes paramètres sans la période, pour la grille mensuelle qui a son propre mois. */
    public function toCalendarQuery(): array
    {
        return array_diff_key($this->toQuery(false), ['from' => 1, 'to' => 1]);
    }

    public function isActive(): bool
    {
        return [] !== $this->toQuery(false);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $date ?: null;
    }

    /** Entier d'un paramètre d'URL ; toute valeur qui n'est pas un entier donne le défaut au lieu d'une erreur 400. */
    private static function int(Request $request, string $key, int $default = 0): int
    {
        $value = $request->query->get($key);

        return \is_string($value) && ctype_digit($value) ? (int) $value : $default;
    }
}
