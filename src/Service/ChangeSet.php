<?php

namespace App\Service;

/** Compare deux photos d'une fiche pour le journal d'activité : seuls les champs modifiés sont gardés. */
final class ChangeSet
{
    /** @return array{avant: array, apres: array}|null null si rien n'a changé */
    public static function diff(array $before, array $after): ?array
    {
        $changed = array_keys(array_filter($after, static fn ($value, string $key): bool => ($before[$key] ?? null) !== $value, \ARRAY_FILTER_USE_BOTH));
        if (!$changed) {
            return null;
        }
        $keep = array_flip($changed);

        return ['avant' => array_intersect_key($before, $keep), 'apres' => array_intersect_key($after, $keep)];
    }
}
