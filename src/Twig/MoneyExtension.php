<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** Filtre `fcfa` : « 1234567.00 » → « 1 234 567 FCFA » (espaces insécables, pour que le montant ne se coupe pas). */
class MoneyExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('fcfa', [self::class, 'format']),
            new TwigFilter('fcfa_number', [self::class, 'number']),
        ];
    }

    public static function format(string|int|null $amount): string
    {
        return self::number($amount).' FCFA';
    }

    /** Le montant sans l'unité, pour les colonnes de tableau. */
    public static function number(string|int|null $amount): string
    {
        $amount = trim((string) $amount);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $amount, $m)) {
            return '0';
        }
        $int = (int) $m[2] + (isset($m[3][0]) && $m[3][0] >= '5' ? 1 : 0);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{00A0}", (string) $int);

        return ('-' === $m[1] && 0 !== $int ? '-' : '').$grouped;
    }
}
