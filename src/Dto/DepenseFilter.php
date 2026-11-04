<?php

namespace App\Dto;

use App\Entity\Depense;
use Symfony\Component\HttpFoundation\Request;

/** Recherche, filtres, tri et page de la liste des dépenses, lus dans la query string (liste et synthèse). */
final class DepenseFilter
{
    public const SORTS = ['date', 'fournisseur', 'categorie', 'ttc', 'echeance', 'paiement'];
    public const ETATS = ['retard' => 'En retard', 'apayer' => 'À payer', 'payee' => 'Payée'];

    public string $q = '';
    public ?string $categorie = null;
    public ?string $etat = null;
    public ?string $mode = null;
    public ?\DateTimeImmutable $from = null;
    public ?\DateTimeImmutable $to = null;
    public string $sort = 'date';
    public string $dir = 'desc';
    public int $page = 1;

    public static function fromRequest(Request $request): self
    {
        $filter = new self();
        $filter->q = mb_substr(trim((string) $request->query->get('q')), 0, 100);
        $categorie = (string) $request->query->get('categorie');
        $filter->categorie = isset(Depense::CATEGORIES[$categorie]) ? $categorie : null;
        $etat = (string) $request->query->get('etat');
        $filter->etat = isset(self::ETATS[$etat]) ? $etat : null;
        $mode = (string) $request->query->get('mode');
        $filter->mode = isset(Depense::MODES[$mode]) ? $mode : null;
        $filter->from = self::date($request->query->get('from'));
        $filter->to = self::date($request->query->get('to'));
        $sort = (string) $request->query->get('sort');
        $filter->sort = \in_array($sort, self::SORTS, true) ? $sort : 'date';
        $filter->dir = 'asc' === $request->query->get('dir') ? 'asc' : 'desc';
        $page = $request->query->get('page');
        $filter->page = max(1, \is_string($page) && ctype_digit($page) ? (int) $page : 1);

        return $filter;
    }

    /** Paramètres d'URL des filtres, pour les liens de tri et de pagination. */
    public function toQuery(bool $withSort = true): array
    {
        $query = array_filter([
            'q' => '' !== $this->q ? $this->q : null,
            'categorie' => $this->categorie,
            'etat' => $this->etat,
            'mode' => $this->mode,
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
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
