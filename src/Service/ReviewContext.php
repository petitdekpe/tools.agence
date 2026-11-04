<?php

namespace App\Service;

/**
 * Le contexte joint à un texte à relire : ce que le Correcteur média « se rappelle ».
 * Les règles de l'équipe vont dans le prompt système (même texte à chaque appel, donc mis en cache) ;
 * le reste va dans le message, avant le texte.
 */
final class ReviewContext
{
    /**
     * @param list<string> $rules   règles de l'équipe (dans l'ordre stable des identifiants)
     * @param list<string> $memory  entrées de mémoire du dossier
     * @param list<string> $recent  derniers posts du dossier, déjà condensés
     */
    public function __construct(
        public readonly array $rules = [],
        public readonly ?string $dossier = null,
        public readonly array $memory = [],
        public readonly array $recent = [],
        public readonly ?string $previousText = null,
        public readonly ?string $previousReview = null,
    ) {
    }

    public function systemBlock(): string
    {
        return [] === $this->rules ? '' : "## RÈGLES DE L'ÉQUIPE\n\n".$this->list($this->rules);
    }

    public function userMessage(string $text): string
    {
        $parts = [];
        if ($this->memory) {
            $parts[] = '## MÉMOIRE DU DOSSIER'.(null !== $this->dossier ? " « {$this->dossier} »" : '')."\n\n".$this->list($this->memory);
        }
        if ($this->recent) {
            $parts[] = "## POSTS RÉCENTS DU DOSSIER (pour la cohérence, ne pas les relire)\n\n".implode("\n\n", $this->recent);
        }
        if (null !== $this->previousText) {
            $parts[] = "## VERSION PRÉCÉDENTE DE CE TEXTE\n\n".$this->previousText;
            $parts[] = "## RELECTURE PRÉCÉDENTE (verdict et remarques)\n\n".($this->previousReview ?: '(indisponible)');
        }
        $parts[] = "## TEXTE À RELIRE\n\n".$text;

        return implode("\n\n", $parts);
    }

    /** @param list<string> $items */
    private function list(array $items): string
    {
        return implode("\n", array_map(static fn (string $i): string => '- '.$i, $items));
    }
}
