<?php

namespace App\Dto;

use App\Entity\Ticket;
use App\Service\Choices;
use Symfony\Component\HttpFoundation\Request;

/** Type, recherche, filtres, tri et page de la liste des pannes et demandes, lus dans la query string. */
final class TicketFilter
{
    public const SORTS = ['date', 'reference', 'titre', 'priorite', 'statut'];

    /** « actifs » regroupe ouvert et en cours. */
    public const ETATS = ['actifs' => 'À traiter'] + Ticket::STATUTS;

    public string $type = Ticket::TYPE_PANNE;
    public string $q = '';
    public ?string $etat = null;
    public ?string $priorite = null;
    public string $sort = 'date';
    public string $dir = 'desc';
    public int $page = 1;

    public static function fromRequest(Request $request): self
    {
        $filter = new self();
        $type = (string) $request->query->get('type');
        $filter->type = isset(Ticket::TYPES[$type]) ? $type : Ticket::TYPE_PANNE;
        $filter->q = mb_substr(trim((string) $request->query->get('q')), 0, 100);
        $etat = (string) $request->query->get('etat');
        $filter->etat = isset(self::ETATS[$etat]) ? $etat : null;
        $priorite = (string) $request->query->get('priorite');
        $filter->priorite = Choices::has('ticket_priorite', $priorite) ? $priorite : null;
        $sort = (string) $request->query->get('sort');
        $filter->sort = \in_array($sort, self::SORTS, true) ? $sort : 'date';
        $filter->dir = 'asc' === $request->query->get('dir') ? 'asc' : 'desc';
        $filter->page = max(1, self::int($request, 'page', 1));

        return $filter;
    }

    /** Paramètres d'URL des filtres, pour les liens de tri et de pagination. Le type en fait toujours partie. */
    public function toQuery(bool $withSort = true): array
    {
        $query = ['type' => $this->type] + array_filter([
            'q' => '' !== $this->q ? $this->q : null,
            'etat' => $this->etat,
            'priorite' => $this->priorite,
        ], static fn ($v) => null !== $v);

        return $withSort ? $query + ['sort' => $this->sort, 'dir' => $this->dir] : $query;
    }

    /** Vrai dès qu'un filtre (hors type) est posé. */
    public function isActive(): bool
    {
        return \count($this->toQuery(false)) > 1;
    }

    /** Entier d'un paramètre d'URL ; toute valeur qui n'est pas un entier donne 0 au lieu d'une erreur 400. */
    private static function int(Request $request, string $key, int $default = 0): int
    {
        $value = $request->query->get($key);

        return \is_string($value) && ctype_digit($value) ? (int) $value : $default;
    }
}
