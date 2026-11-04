<?php

namespace App\Dto;

use App\Entity\Document;
use Symfony\Component\HttpFoundation\Request;

/** Type, recherche, filtres, tri et page de la liste des factures et devis, lus dans la query string. */
final class DocumentFilter
{
    public const SORTS = ['date', 'reference', 'client', 'ttc', 'echeance'];

    /** Filtres d'état proposés par type : les statuts choisis, plus les états déduits (règlement, retard, expiration). */
    public const ETATS = [
        Document::TYPE_FACTURE => [
            'retard' => 'En retard',
            'impayee' => 'Impayée',
            'partielle' => 'Payée en partie',
            'soldee' => 'Soldée',
            Document::BROUILLON => 'Brouillon',
            Document::ANNULEE => 'Annulée',
        ],
        Document::TYPE_DEVIS => [
            Document::BROUILLON => 'Brouillon',
            Document::ENVOYE => 'Envoyé',
            Document::ACCEPTE => 'Accepté',
            Document::REFUSE => 'Refusé',
            'expire' => 'Expiré',
            'afacturer' => 'À facturer',
        ],
    ];

    public string $type = Document::TYPE_FACTURE;
    public string $q = '';
    public ?int $client = null;
    public ?string $etat = null;
    public ?\DateTimeImmutable $from = null;
    public ?\DateTimeImmutable $to = null;
    public string $sort = 'date';
    public string $dir = 'desc';
    public int $page = 1;

    public static function fromRequest(Request $request): self
    {
        $filter = new self();
        $type = (string) $request->query->get('type');
        $filter->type = isset(Document::TYPES[$type]) ? $type : Document::TYPE_FACTURE;
        $filter->q = mb_substr(trim((string) $request->query->get('q')), 0, 100);
        $filter->client = self::int($request, 'client') ?: null;
        $etat = (string) $request->query->get('etat');
        $filter->etat = isset(self::ETATS[$filter->type][$etat]) ? $etat : null;
        $filter->from = self::date($request->query->get('from'));
        $filter->to = self::date($request->query->get('to'));
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
            'client' => $this->client,
            'etat' => $this->etat,
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
        ], static fn ($v) => null !== $v);

        return $withSort ? $query + ['sort' => $this->sort, 'dir' => $this->dir] : $query;
    }

    /** Vrai dès qu'un filtre (hors type) est posé. */
    public function isActive(): bool
    {
        return \count($this->toQuery(false)) > 1;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $date ?: null;
    }

    /** Entier d'un paramètre d'URL ; toute valeur qui n'est pas un entier donne 0 au lieu d'une erreur 400. */
    private static function int(Request $request, string $key, int $default = 0): int
    {
        $value = $request->query->get($key);

        return \is_string($value) && ctype_digit($value) ? (int) $value : $default;
    }
}
