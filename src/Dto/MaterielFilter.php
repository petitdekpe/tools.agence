<?php

namespace App\Dto;

use App\Service\Choices;
use Symfony\Component\HttpFoundation\Request;

/** Recherche, filtres, tri et page de l'inventaire, lus dans la query string (liste et exports). */
final class MaterielFilter
{
    public const SORTS = ['designation', 'categorie', 'reference', 'etat', 'detenteur', 'achat', 'valeur'];
    public const AFFECTATIONS = [
        'disponible' => 'Disponible',
        'affecte' => 'Affecté',
        'parti' => 'Détenteur parti',
    ];

    public string $q = '';
    public ?string $categorie = null;
    public ?string $etat = null;
    public ?string $affectation = null;
    public ?int $detenteur = null;
    public string $sort = 'designation';
    public string $dir = 'asc';
    public int $page = 1;

    public static function fromRequest(Request $request): self
    {
        $filter = new self();
        $filter->q = mb_substr(trim((string) $request->query->get('q')), 0, 100);
        $categorie = (string) $request->query->get('categorie');
        $filter->categorie = Choices::has('materiel_category', $categorie) ? $categorie : null;
        $etat = (string) $request->query->get('etat');
        $filter->etat = Choices::has('materiel_etat', $etat) ? $etat : null;
        $affectation = (string) $request->query->get('affectation');
        $filter->affectation = isset(self::AFFECTATIONS[$affectation]) ? $affectation : null;
        $filter->detenteur = $request->query->getInt('detenteur') ?: null;
        $sort = (string) $request->query->get('sort');
        $filter->sort = \in_array($sort, self::SORTS, true) ? $sort : 'designation';
        $filter->dir = 'desc' === $request->query->get('dir') ? 'desc' : 'asc';
        $filter->page = max(1, $request->query->getInt('page', 1));

        return $filter;
    }

    /** Paramètres d'URL des filtres, pour les liens de tri, de pagination et d'export. */
    public function toQuery(bool $withSort = true): array
    {
        $query = array_filter([
            'q' => '' !== $this->q ? $this->q : null,
            'categorie' => $this->categorie,
            'etat' => $this->etat,
            'affectation' => $this->affectation,
            'detenteur' => $this->detenteur,
        ], static fn ($v) => null !== $v);

        return $withSort ? $query + ['sort' => $this->sort, 'dir' => $this->dir] : $query;
    }

    public function isActive(): bool
    {
        return [] !== $this->toQuery(false);
    }
}
