<?php

namespace App\Tests\Service;

use App\Entity\Publication;
use App\Service\CalendarFeed;
use App\Service\DialCodes;
use App\Service\Zodiac;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Signe astrologique, indicatifs téléphoniques et flux iCalendar : des calculs sans base de données. */
class ProfileHelpersTest extends TestCase
{
    #[DataProvider('births')]
    public function testLeSigneSeDeduitDeLaDateDeNaissance(string $date, string $sign): void
    {
        self::assertSame($sign, Zodiac::of(new \DateTimeImmutable($date))['label']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function births(): iterable
    {
        yield 'fin du Capricorne en janvier' => ['2000-01-19', 'Capricorne'];
        yield 'début du Verseau' => ['2000-01-20', 'Verseau'];
        yield 'dernier jour du Verseau' => ['2001-02-18', 'Verseau'];
        yield 'début des Poissons' => ['2001-02-19', 'Poissons'];
        yield 'Bélier' => ['1995-03-25', 'Bélier'];
        yield 'bissextile 29 février' => ['2000-02-29', 'Poissons'];
        yield 'Lion' => ['1990-08-05', 'Lion'];
        yield 'dernier jour du Scorpion' => ['1990-11-21', 'Scorpion'];
        yield 'début du Sagittaire' => ['1990-11-22', 'Sagittaire'];
        yield 'dernier jour du Sagittaire' => ['1990-12-21', 'Sagittaire'];
        yield 'début du Capricorne en décembre' => ['1990-12-22', 'Capricorne'];
        yield 'Noël' => ['1990-12-25', 'Capricorne'];
    }

    #[DataProvider('phones')]
    public function testUnNumeroSeSepareEnIndicatifEtNumero(?string $stored, string $code, string $number): void
    {
        self::assertSame(['code' => $code, 'number' => $number], DialCodes::split($stored));
    }

    /** @return iterable<string, array{?string, string, string}> */
    public static function phones(): iterable
    {
        yield 'avec indicatif béninois' => ['+229 0197000000', '+229', '0197000000'];
        yield 'collé' => ['+2290197000000', '+229', '0197000000'];
        yield 'indicatif à un chiffre' => ['+1 555 0100', '+1', '5550100'];
        yield 'France' => ['+33 6 12 34 56 78', '+33', '612345678'];
        yield 'sans indicatif : le Bénin par défaut' => ['0197000000', '+229', '0197000000'];
        yield 'vide' => [null, '+229', ''];
    }

    public function testLeFluxICalendarEstValide(): void
    {
        $long = 'Lancement de la campagne « Rentrée 2026 » ; version, avec une légende vraiment très longue pour forcer le pliage des lignes';
        $timed = (new Publication())->setTitre($long)->setDatePublication(new \DateTimeImmutable('2026-11-10'))->setHeure(new \DateTimeImmutable('09:30'));
        $allDay = (new Publication())->setTitre('Newsletter')->setDatePublication(new \DateTimeImmutable('2026-11-12'))->setStatut(Publication::ANNULE);

        $ics = (new CalendarFeed())->render([$timed, $allDay], 'Calendrier éditorial', 'tools.example.com', static fn (): string => 'https://tools.example.com/calendrier/1');

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        // 09:30 au Bénin (UTC+1) : 08:30 UTC ; l'événement sans heure dure toute la journée.
        self::assertStringContainsString('DTSTART:20261110T083000Z', $ics);
        self::assertStringContainsString('DTSTART;VALUE=DATE:20261112', $ics);
        self::assertStringContainsString('DTEND;VALUE=DATE:20261113', $ics);
        self::assertStringContainsString('STATUS:CANCELLED', $ics);
        // Virgules et points-virgules du texte sont échappés (la dépliée de la ligne contient « \; » et « \, »).
        $unfolded = str_replace("\r\n ", '', $ics);
        self::assertStringContainsString('\; version\, avec', $unfolded);
        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, \strlen($line), 'ligne trop longue : '.$line);
        }
        self::assertTrue(mb_check_encoding($ics, 'UTF-8'), 'le pliage ne coupe aucun caractère');
    }
}
