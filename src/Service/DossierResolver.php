<?php

namespace App\Service;

use App\Entity\CorrectionMemory;
use App\Entity\User;
use App\Repository\CorrectionRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Devine à quel dossier appartient un texte : le nom d'un dossier connu (ou ses mots) y figure, ou une entrée de sa mémoire y est citée. */
class DossierResolver
{
    /** Les mots plus courts (« Mois », « de ») ne désignent aucun sujet. */
    private const MIN_WORD = 5;

    public function __construct(private readonly CorrectionRepository $corrections, private readonly EntityManagerInterface $em)
    {
    }

    public function suggest(User $user, string $text): ?string
    {
        $dossiers = $this->corrections->dossiers($user);
        if (!$dossiers) {
            return null;
        }

        $haystack = mb_strtolower($text);
        $compact = preg_replace('/[^\p{L}\p{N}]+/u', '', $haystack) ?? $haystack; // « #ConsommonsLocal » contient « consommons local »

        $anchors = [];
        foreach ($this->em->getRepository(CorrectionMemory::class)->findBy(['user' => $user, 'shared' => false]) as $entry) {
            if (null !== $entry->getDossier() && null !== $entry->getAnchor() && mb_strlen($entry->getAnchor()) >= 4) {
                $anchors[mb_strtolower($entry->getDossier())][] = mb_strtolower($entry->getAnchor());
            }
        }

        $best = null;
        $bestScore = 0;
        foreach ($dossiers as $dossier) { // du plus récemment utilisé au plus ancien : à égalité, le plus récent gagne
            $score = $this->score($dossier['name'], $anchors[mb_strtolower($dossier['name'])] ?? [], $haystack, $compact);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$dossier['name'], $score];
            }
        }

        return $best;
    }

    /** @param list<string> $anchors */
    private function score(string $name, array $anchors, string $haystack, string $compact): int
    {
        $lower = mb_strtolower($name);
        if (mb_strlen($lower) >= 3 && str_contains($haystack, $lower)) {
            return 100;
        }

        $score = 0;
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $lower) ?: [], static fn (string $w): bool => mb_strlen($w) >= self::MIN_WORD));
        if ($words) {
            $found = \count(array_filter($words, static fn (string $w): bool => str_contains($compact, $w)));
            // Tous les mots significatifs d'un nom court ; au moins la moitié d'un nom long.
            if ($found >= (\count($words) <= 2 ? \count($words) : (int) ceil(\count($words) / 2))) {
                $score += 10 * $found;
            }
        }
        foreach ($anchors as $anchor) {
            if (str_contains($haystack, $anchor)) {
                ++$score;
            }
        }

        return $score;
    }
}
