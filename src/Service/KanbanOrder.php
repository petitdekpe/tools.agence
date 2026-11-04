<?php

namespace App\Service;

/**
 * Range une carte à un rang précis dans sa colonne : les autres cartes se décalent et tous les rangs sont renumérotés de 0 à n-1.
 * Fonctionne avec toute entité qui expose getPosition() et setPosition().
 */
final class KanbanOrder
{
    /**
     * @template T of object
     *
     * @param list<T> $column les cartes déjà dans la colonne cible, dans l'ordre actuel (la carte déplacée peut en faire partie)
     * @param T       $moved  la carte déplacée
     */
    public static function place(array $column, object $moved, int $index): void
    {
        $others = array_values(array_filter($column, static fn (object $card): bool => $card !== $moved));
        $index = max(0, min($index, \count($others)));
        array_splice($others, $index, 0, [$moved]);

        foreach ($others as $position => $card) {
            if ($card->getPosition() !== $position) {
                $card->setPosition($position);
            }
        }
    }
}
