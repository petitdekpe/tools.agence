<?php

namespace App\Dto;

use App\Service\Choices;
use Symfony\Component\HttpFoundation\Request;

/** Filtres, tri et page de la liste des encaissements, lus dans la query string (liste, exports et synthèse). */
final class EncaissementFilter
{
    public const SORTS = ['date', 'client', 'nature', 'ttc', 'ht', 'tva_source', 'tva_reverser', 'aib', 'net', 'especes', 'cheque', 'observation', 'coherence'];
    public const MODES = ['especes' => 'Espèces / MoMo', 'virement' => 'Chèque / virement'];
    public const COHERENCES = ['ok' => 'OK', 'ecart' => 'Écart'];

    public ?\DateTimeImmutable $from = null;
    public ?\DateTimeImmutable $to = null;
    public ?int $client = null;
    public ?string $nature = null;
    public ?string $mode = null;
    public ?string $coherence = null;
    public string $q = '';
    public string $sort = 'date';
    public string $dir = 'desc';
    public int $page = 1;

    public static function fromRequest(Request $request): self
    {
        $filter = new self();
        $filter->from = self::date($request->query->get('from'));
        $filter->to = self::date($request->query->get('to'));
        $filter->client = $request->query->getInt('client') ?: null;
        $nature = (string) $request->query->get('nature');
        $filter->nature = Choices::has('encaissement_nature', $nature) ? $nature : null;
        $mode = (string) $request->query->get('mode');
        $filter->mode = isset(self::MODES[$mode]) ? $mode : null;
        $coherence = (string) $request->query->get('coherence');
        $filter->coherence = isset(self::COHERENCES[$coherence]) ? $coherence : null;
        $filter->q = mb_substr(trim((string) $request->query->get('q')), 0, 100);
        $sort = (string) $request->query->get('sort');
        $filter->sort = \in_array($sort, self::SORTS, true) ? $sort : 'date';
        $filter->dir = 'asc' === $request->query->get('dir') ? 'asc' : 'desc';
        $filter->page = max(1, $request->query->getInt('page', 1));

        return $filter;
    }

    /** Paramètres d'URL des filtres seuls, pour les liens de tri, de pagination et d'export. */
    public function toQuery(bool $withSort = true): array
    {
        $query = array_filter([
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
            'client' => $this->client,
            'nature' => $this->nature,
            'mode' => $this->mode,
            'coherence' => $this->coherence,
            'q' => '' !== $this->q ? $this->q : null,
        ], static fn ($v) => null !== $v);

        return $withSort ? $query + ['sort' => $this->sort, 'dir' => $this->dir] : $query;
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
}
