<?php

namespace App\Service;

/** Signe du zodiaque déduit d'une date de naissance. */
final class Zodiac
{
    /** Dernier jour de chaque signe, dans l'ordre de l'année : [mois, jour, clé] (le Capricorne revient en fin d'année). */
    private const LIMITS = [
        [1, 19, 'capricorne'], [2, 18, 'verseau'], [3, 20, 'poissons'], [4, 19, 'belier'], [5, 20, 'taureau'], [6, 20, 'gemeaux'],
        [7, 22, 'cancer'], [8, 22, 'lion'], [9, 22, 'vierge'], [10, 22, 'balance'], [11, 21, 'scorpion'], [12, 21, 'sagittaire'],
    ];

    public const SIGNS = [
        'belier' => ['Bélier', '♈'],
        'taureau' => ['Taureau', '♉'],
        'gemeaux' => ['Gémeaux', '♊'],
        'cancer' => ['Cancer', '♋'],
        'lion' => ['Lion', '♌'],
        'vierge' => ['Vierge', '♍'],
        'balance' => ['Balance', '♎'],
        'scorpion' => ['Scorpion', '♏'],
        'sagittaire' => ['Sagittaire', '♐'],
        'capricorne' => ['Capricorne', '♑'],
        'verseau' => ['Verseau', '♒'],
        'poissons' => ['Poissons', '♓'],
    ];

    public static function key(\DateTimeInterface $birth): string
    {
        $month = (int) $birth->format('n');
        $day = (int) $birth->format('j');
        foreach (self::LIMITS as [$limitMonth, $limitDay, $key]) {
            if ($month < $limitMonth || ($month === $limitMonth && $day <= $limitDay)) {
                return $key;
            }
        }

        return 'capricorne';
    }

    /** @return array{key: string, label: string, symbol: string} */
    public static function of(\DateTimeInterface $birth): array
    {
        $key = self::key($birth);

        return ['key' => $key, 'label' => self::SIGNS[$key][0], 'symbol' => self::SIGNS[$key][1]];
    }
}
