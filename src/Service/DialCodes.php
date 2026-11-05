<?php

namespace App\Service;

/** Indicatifs téléphoniques proposés dans les formulaires : code => pays. Le Bénin est l'indicatif par défaut. */
final class DialCodes
{
    public const DEFAULT = '+229';

    public const COUNTRIES = [
        '+229' => 'Bénin',
        '+228' => 'Togo',
        '+225' => 'Côte d\'Ivoire',
        '+233' => 'Ghana',
        '+234' => 'Nigeria',
        '+221' => 'Sénégal',
        '+223' => 'Mali',
        '+226' => 'Burkina Faso',
        '+227' => 'Niger',
        '+224' => 'Guinée',
        '+220' => 'Gambie',
        '+231' => 'Libéria',
        '+232' => 'Sierra Leone',
        '+222' => 'Mauritanie',
        '+238' => 'Cap-Vert',
        '+235' => 'Tchad',
        '+237' => 'Cameroun',
        '+236' => 'Centrafrique',
        '+241' => 'Gabon',
        '+242' => 'Congo-Brazzaville',
        '+243' => 'RD Congo',
        '+240' => 'Guinée équatoriale',
        '+244' => 'Angola',
        '+250' => 'Rwanda',
        '+257' => 'Burundi',
        '+254' => 'Kenya',
        '+255' => 'Tanzanie',
        '+256' => 'Ouganda',
        '+251' => 'Éthiopie',
        '+253' => 'Djibouti',
        '+261' => 'Madagascar',
        '+230' => 'Maurice',
        '+27' => 'Afrique du Sud',
        '+212' => 'Maroc',
        '+213' => 'Algérie',
        '+216' => 'Tunisie',
        '+218' => 'Libye',
        '+20' => 'Égypte',
        '+33' => 'France',
        '+32' => 'Belgique',
        '+41' => 'Suisse',
        '+352' => 'Luxembourg',
        '+49' => 'Allemagne',
        '+44' => 'Royaume-Uni',
        '+39' => 'Italie',
        '+34' => 'Espagne',
        '+351' => 'Portugal',
        '+31' => 'Pays-Bas',
        '+1' => 'États-Unis / Canada',
        '+55' => 'Brésil',
        '+971' => 'Émirats arabes unis',
        '+966' => 'Arabie saoudite',
        '+90' => 'Turquie',
        '+86' => 'Chine',
        '+91' => 'Inde',
        '+81' => 'Japon',
    ];

    /** @return array<string, string> libellé du choix (« Bénin (+229) ») => indicatif */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::COUNTRIES as $code => $country) {
            $choices[$country.' ('.$code.')'] = $code;
        }

        return $choices;
    }

    /**
     * Sépare un numéro enregistré (« +229 0197000000 ») en indicatif et numéro. Un numéro sans indicatif reconnu
     * garde l'indicatif par défaut.
     *
     * @return array{code: string, number: string}
     */
    public static function split(?string $value): array
    {
        $value = trim((string) $value);
        if (str_starts_with($value, '+')) {
            $compact = preg_replace('/\s+/', '', $value);
            $codes = array_keys(self::COUNTRIES);
            usort($codes, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
            foreach ($codes as $code) {
                if (str_starts_with($compact, $code)) {
                    return ['code' => $code, 'number' => substr($compact, \strlen($code))];
                }
            }
        }

        return ['code' => self::DEFAULT, 'number' => $value];
    }
}
