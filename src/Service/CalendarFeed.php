<?php

namespace App\Service;

use App\Entity\Publication;

/**
 * Flux iCalendar (RFC 5545) du calendrier éditorial, auquel s'abonnent Google Agenda, Apple Calendrier ou Outlook.
 * Une publication avec heure devient un événement de 30 minutes ; sans heure, un événement sur la journée.
 */
class CalendarFeed
{
    /** Fuseau des heures saisies dans le calendrier (Bénin : UTC+1, sans heure d'été). */
    public const TIMEZONE = 'Africa/Porto-Novo';

    /**
     * @param iterable<Publication> $publications
     * @param callable(Publication): string $urlOf adresse absolue de la publication dans l'application
     */
    public function render(iterable $publications, string $calendarName, string $host, callable $urlOf): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//PROCOM Tools//Calendrier editorial//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($calendarName),
            'X-WR-TIMEZONE:'.self::TIMEZONE,
            // Indice de fréquence de rafraîchissement : les clients l'appliquent à leur guise (Google, le moins souvent).
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ];

        foreach ($publications as $publication) {
            array_push($lines, ...$this->event($publication, $host, $urlOf($publication)));
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /** @return list<string> */
    private function event(Publication $publication, string $host, string $url): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $utc = new \DateTimeZone('UTC');
        $day = $publication->getDatePublication();
        $updated = $publication->getUpdatedAt()->setTimezone($utc);

        $lines = [
            'BEGIN:VEVENT',
            'UID:publication-'.$publication->getId().'@'.$host,
            'DTSTAMP:'.$updated->format('Ymd\THis\Z'),
            'LAST-MODIFIED:'.$updated->format('Ymd\THis\Z'),
            'SEQUENCE:'.$updated->getTimestamp(),
        ];
        if ($publication->getHeure()) {
            $start = new \DateTimeImmutable($day->format('Y-m-d').' '.$publication->getHeure()->format('H:i:s'), $tz);
            $lines[] = 'DTSTART:'.$start->setTimezone($utc)->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'.$start->modify('+30 minutes')->setTimezone($utc)->format('Ymd\THis\Z');
        } else {
            $lines[] = 'DTSTART;VALUE=DATE:'.$day->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$day->modify('+1 day')->format('Ymd');
        }

        $client = $publication->getClient()?->getName();
        $description = array_filter([
            'Statut : '.$publication->getStatutLabel(),
            'Canal : '.$publication->getCanalLabel().' ('.$publication->getFormatLabel().')',
            $client ? 'Client : '.$client : null,
            $publication->getResponsable() ? 'Responsable : '.$publication->getResponsable()->getFullName() : null,
            $publication->getLien() ? 'Publication en ligne : '.$publication->getLien() : null,
            'Dans PROCOM Tools : '.$url,
        ]);

        $lines[] = 'SUMMARY:'.$this->escape($publication->getCanalLabel().' · '.$publication->getTitre().($client ? ' ('.$client.')' : ''));
        $lines[] = 'DESCRIPTION:'.$this->escape(implode("\n", $description));
        $lines[] = 'URL:'.$url;
        $lines[] = 'STATUS:'.match ($publication->getStatut()) {
            Publication::ANNULE => 'CANCELLED',
            Publication::VALIDE, Publication::PUBLIE => 'CONFIRMED',
            default => 'TENTATIVE',
        };
        $lines[] = 'CATEGORIES:'.$this->escape($publication->getCanalLabel());
        $lines[] = 'TRANSP:TRANSPARENT';
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $text);
    }

    /** Coupe à 75 octets par ligne (RFC 5545 §3.1) sans couper un caractère UTF-8 ; la suite commence par une espace. */
    private function fold(string $line): string
    {
        if (\strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $current = '';
        $limit = 75;
        foreach (mb_str_split($line) as $char) {
            if (\strlen($current) + \strlen($char) > $limit) {
                $out .= $current."\r\n ";
                $current = '';
                $limit = 74;
            }
            $current .= $char;
        }

        return $out.$current;
    }
}
