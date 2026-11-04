<?php

namespace App\Tests\Service;

use App\Service\RemarkParser;
use PHPUnit\Framework\TestCase;

class RemarkParserTest extends TestCase
{
    private const RESULT = <<<'TXT'
Verdict : publiable après corrections — 4 corrections, 1 point à vérifier (bloquant).

**REMARQUES**

**1. 🔴 « Sellopas,votre » → « Sellopas, votre ».**
Espace manquante après la virgule (titre). Phrase deux.

**2. 🟠 Structure — CTA et URL au milieu du post.**
Le lien précède le bloc de description.

**5. ⚠️ À vérifier (bloquant) — nom de la marque.**
« Sellopas » dans le texte, « Sellopass.com » dans l'URL.
TXT;

    public function testParsesRemarksByPosition(): void
    {
        $remarks = RemarkParser::parse(self::RESULT);

        self::assertSame([1, 2, 3], array_column($remarks, 'n'), 'Le numéro est la position, pas le numéro imprimé.');
        self::assertSame(['🔴', '🟠', '⚠️'], array_column($remarks, 'pastille'));
        self::assertSame('« Sellopas,votre » → « Sellopas, votre ».', $remarks[0]['heading']);
        self::assertSame('Espace manquante après la virgule (titre). Phrase deux.', $remarks[0]['body']);
        self::assertSame('Sellopas,votre', $remarks[0]['quote']);
        self::assertNull($remarks[1]['quote']);
        self::assertSame('Sellopas', $remarks[2]['quote']);
    }

    public function testIgnoresVerdictTitleAndPlainParagraphs(): void
    {
        self::assertSame([], RemarkParser::parse("Verdict : publiable en l'état.\n\nRien à signaler."));
        self::assertSame([], RemarkParser::parse(''));
    }

    public function testParsesRemarksOfVisualsCardByCard(): void
    {
        $remarks = RemarkParser::parse("**Carte 1 — 1. 🔴 « a » → « b ».**\nRaison.\n\n**Carte 2 — 1. 🔵 « c » → « d ».**\nAutre raison.");

        self::assertSame([1, 2], array_column($remarks, 'n'));
        self::assertSame(['« a » → « b ».', '« c » → « d ».'], array_column($remarks, 'heading'));
    }

    public function testDigestKeepsTheVerdictAndTheHeadings(): void
    {
        $digest = RemarkParser::digest(self::RESULT);

        self::assertSame(
            "Verdict : publiable après corrections — 4 corrections, 1 point à vérifier (bloquant).\n- 🔴 « Sellopas,votre » → « Sellopas, votre ».\n- 🟠 Structure — CTA et URL au milieu du post.\n- ⚠️ À vérifier (bloquant) — nom de la marque.",
            $digest,
        );
        self::assertStringNotContainsString('Espace manquante', $digest);
    }

    public function testClipIsBoundedAndKeepsShortTextsAsTheyAre(): void
    {
        self::assertSame('court', RemarkParser::clip('court', 10));
        self::assertSame(10, mb_strlen(RemarkParser::clip(str_repeat('é', 50), 10)));
        self::assertSame(str_repeat('é', 9).'…', RemarkParser::clip(str_repeat('é', 50), 10));
        self::assertLessThanOrEqual(80, mb_strlen(RemarkParser::digest(self::RESULT, 80)));
    }
}
