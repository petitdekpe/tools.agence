<?php

namespace App\Service;

/** Une réponse du Correcteur média et ce que l'appel a consommé. */
final readonly class MediaReview
{
    /**
     * @param int $inputTokens      tokens d'entrée facturés au tarif plein (hors cache)
     * @param int $cacheReadTokens  tokens d'entrée relus dans le cache du prompt (environ dix fois moins chers)
     * @param int $cacheWriteTokens tokens d'entrée écrits dans le cache du prompt
     */
    public function __construct(
        public string $text,
        public ?string $model = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {
    }
}
