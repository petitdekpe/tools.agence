<?php

namespace App\Service;

/**
 * Lit la réponse du Correcteur média (format de la section 7 du prompt) : une ligne de verdict, puis des remarques
 * « **N. 🔴 « avant » → « après ».** », chacune suivie de sa raison dans le même bloc.
 * Le numéro d'une remarque est sa position dans la réponse (les visuels recommencent la numérotation à chaque carte).
 * L'interface (correcteur_controller.js) reconnaît les remarques avec la même expression.
 */
final class RemarkParser
{
    private const HEADING = '/^\*\*\s*(?:Carte\s*\d+\s*[—–-]\s*)?\d+\.\s*(.*?)\s*\*\*\s*$/u';
    private const PASTILLE = '/^(🔴|🟠|🔵|⚠️?)\s*/u';

    /** @return list<array{n: int, pastille: string, heading: string, body: string, quote: ?string}> */
    public static function parse(string $result): array
    {
        $remarks = [];
        foreach (preg_split('/\n{2,}/', trim($result)) ?: [] as $block) {
            $lines = preg_split('/\R/u', trim($block)) ?: [];
            if (!preg_match(self::HEADING, $lines[0] ?? '', $m)) {
                continue;
            }
            $pastille = preg_match(self::PASTILLE, $m[1], $p) ? $p[1] : '';
            $heading = trim(preg_replace(self::PASTILLE, '', $m[1]) ?? $m[1]);
            $body = trim(implode(' ', \array_slice($lines, 1)));
            $remarks[] = [
                'n' => \count($remarks) + 1,
                'pastille' => $pastille,
                'heading' => $heading,
                'body' => $body,
                // Le texte cité : dans le titre, sinon dans la raison (les points « À vérifier » citent dans leur corps).
                'quote' => preg_match('/«\s*(.+?)\s*»/u', $heading, $q) || preg_match('/«\s*(.+?)\s*»/u', $body, $q) ? $q[1] : null,
            ];
        }

        return $remarks;
    }

    /** Le verdict et les titres des remarques : ce qu'il faut pour se rappeler une relecture sans la renvoyer en entier. */
    public static function digest(string $result, int $maxChars = 1200): string
    {
        $lines = [];
        if (preg_match('/^Verdict\s*:.*$/mu', $result, $v)) {
            $lines[] = trim($v[0]);
        }
        foreach (self::parse($result) as $r) {
            $lines[] = '- '.('' !== $r['pastille'] ? $r['pastille'].' ' : '').self::clip($r['heading'], 160);
        }

        return self::clip(implode("\n", $lines), $maxChars);
    }

    /** Coupe à `$max` caractères, « … » compris. */
    public static function clip(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }
}
