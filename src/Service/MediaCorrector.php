<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Appelle l'API Anthropic avec le prompt « Correcteur média ».
 *
 * Le prompt système est le même à chaque appel : il est mis en cache (`cache_control`), ce qui divise par dix le prix de ses tokens
 * dès le deuxième appel. Tout ce qui varie (mémoire, posts récents, texte) vient après, dans le message.
 */
class MediaCorrector
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(ANTHROPIC_API_KEY)%')] private readonly string $apiKey,
        #[Autowire('%env(ANTHROPIC_MODEL)%')] private readonly string $model,
        #[Autowire('%kernel.project_dir%/resources/prompts/correcteur_media.md')] private readonly string $promptFile,
        #[Autowire('%kernel.project_dir%/resources/prompts/correcteur_contexte.md')] private readonly string $contextFile,
    ) {
    }

    public function review(string $text, ReviewContext $context = new ReviewContext()): MediaReview
    {
        if ('' === $this->apiKey) {
            throw new \RuntimeException('ANTHROPIC_API_KEY n\'est pas configurée.');
        }

        $response = $this->http->request('POST', 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'timeout' => 120,
            'json' => [
                'model' => $this->model,
                'max_tokens' => 4096,
                'system' => $this->system($context),
                'messages' => [
                    ['role' => 'user', 'content' => $context->userMessage($text)],
                ],
            ],
        ]);

        $data = $response->toArray(false);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException($data['error']['message'] ?? 'Erreur de l\'API Anthropic.');
        }

        $out = '';
        foreach ($data['content'] ?? [] as $block) {
            if ('text' === ($block['type'] ?? null)) {
                $out .= $block['text'];
            }
        }

        $usage = $data['usage'] ?? [];

        return new MediaReview(
            trim($out),
            $data['model'] ?? $this->model,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (int) ($usage['cache_read_input_tokens'] ?? 0),
            (int) ($usage['cache_creation_input_tokens'] ?? 0),
        );
    }

    /**
     * Deux points de cache : le prompt et son guide du contexte (identiques pour tous), puis les règles de l'équipe (rarement modifiées).
     * Si les règles changent, le premier bloc reste lu dans le cache.
     *
     * @return list<array<string, mixed>>
     */
    private function system(ReviewContext $context): array
    {
        $cache = ['type' => 'ephemeral'];
        $blocks = [[
            'type' => 'text',
            'text' => rtrim((string) file_get_contents($this->promptFile))."\n\n---\n\n".rtrim((string) file_get_contents($this->contextFile)),
            'cache_control' => $cache,
        ]];
        if ('' !== $rules = $context->systemBlock()) {
            $blocks[] = ['type' => 'text', 'text' => $rules, 'cache_control' => $cache];
        }

        return $blocks;
    }
}
