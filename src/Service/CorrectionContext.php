<?php

namespace App\Service;

use App\Entity\Correction;
use App\Entity\CorrectionMemory;
use App\Entity\User;
use App\Repository\CorrectionMemoryRepository;
use App\Repository\CorrectionRepository;

/**
 * Compose le contexte d'une relecture à partir de la mémoire et du fil de l'utilisateur, dans un budget fixe :
 * le coût d'une relecture ne dépend donc pas de la longueur du fil.
 */
class CorrectionContext
{
    private const RULES_MAX = 30;
    private const RULES_CHARS = 3000;
    private const MEMORY_MAX = 40;
    private const MEMORY_CHARS = 2000;
    private const RECENT_POSTS = 3;
    private const RECENT_CHARS = 6000;
    private const EXCERPT_CHARS = 500;
    private const DIGEST_CHARS = 600;
    private const PREVIOUS_DIGEST_CHARS = 1500;

    public function __construct(private readonly CorrectionMemoryRepository $memories, private readonly CorrectionRepository $corrections)
    {
    }

    /**
     * Les entrées de mémoire retenues sont marquées comme utilisées : l'appelant enregistre (flush) une fois la relecture réussie.
     *
     * @param Correction|null $previous la relecture dont le texte est une version révisée
     */
    public function build(User $user, ?string $dossier, string $text, ?Correction $previous = null): ReviewContext
    {
        $rules = $this->fit(array_map(static fn (CorrectionMemory $m): string => $m->getContent(), $this->memories->shared()), self::RULES_MAX, self::RULES_CHARS);

        $used = $this->selectMemory($this->memories->forDossier($user, $dossier), $text);
        foreach ($used as $entry) {
            $entry->markUsed();
        }

        return new ReviewContext(
            rules: $rules,
            dossier: $dossier,
            memory: array_map(static fn (CorrectionMemory $m): string => $m->getContent(), $used),
            recent: null === $dossier ? [] : $this->recent($user, $dossier, $previous),
            previousText: $previous?->getInputText(),
            previousReview: null === $previous ? null : RemarkParser::digest($previous->getResultText(), self::PREVIOUS_DIGEST_CHARS),
        );
    }

    /**
     * Les entrées dont le texte cité figure dans le texte à relire d'abord, puis les plus récentes, dans le budget.
     *
     * @param list<CorrectionMemory> $entries du plus récent au plus ancien
     *
     * @return list<CorrectionMemory>
     */
    private function selectMemory(array $entries, string $text): array
    {
        $matching = $others = [];
        foreach ($entries as $entry) {
            $anchor = $entry->getAnchor();
            if (null !== $anchor && false !== mb_stripos($text, $anchor)) {
                $matching[] = $entry;
            } else {
                $others[] = $entry;
            }
        }

        $selected = [];
        $chars = 0;
        foreach ([...$matching, ...$others] as $entry) {
            $chars += mb_strlen($entry->getContent()) + 3;
            if (\count($selected) >= self::MEMORY_MAX || $chars > self::MEMORY_CHARS) {
                break;
            }
            $selected[] = $entry;
        }

        return $selected;
    }

    /** @return list<string> */
    private function recent(User $user, string $dossier, ?Correction $previous): array
    {
        $exclude = [];
        for ($c = $previous; null !== $c; $c = $c->getPrevious()) {
            $exclude[] = $c->getId();
        }

        $posts = [];
        foreach (array_reverse($this->corrections->recentInDossier($user, $dossier, self::RECENT_POSTS, $exclude)) as $c) {
            $excerpt = RemarkParser::clip(preg_replace('/\s*\R\s*/u', ' ⏎ ', $c->getInputText()) ?? '', self::EXCERPT_CHARS);
            $posts[] = "### {$c->getCreatedAt()->format('d/m/Y')} · {$c->getTitle()}\nTexte : {$excerpt}\nRelecture : ".RemarkParser::digest($c->getResultText(), self::DIGEST_CHARS);
        }

        return $this->fit($posts, self::RECENT_POSTS, self::RECENT_CHARS, true);
    }

    /**
     * @param list<string> $items
     * @param bool         $keepLast en cas de dépassement, garder les derniers éléments (les plus récents) plutôt que les premiers
     *
     * @return list<string>
     */
    private function fit(array $items, int $maxItems, int $maxChars, bool $keepLast = false): array
    {
        $ordered = $keepLast ? array_reverse($items) : $items;
        $kept = [];
        $chars = 0;
        foreach ($ordered as $item) {
            $chars += mb_strlen($item) + 3;
            if (\count($kept) >= $maxItems || $chars > $maxChars) {
                break;
            }
            $kept[] = $item;
        }

        return $keepLast ? array_reverse($kept) : $kept;
    }
}
