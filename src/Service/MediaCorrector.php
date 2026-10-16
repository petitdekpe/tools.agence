<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Appelle l'API Anthropic avec le prompt « Correcteur média ». */
class MediaCorrector
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(ANTHROPIC_API_KEY)%')] private readonly string $apiKey,
        #[Autowire('%env(ANTHROPIC_MODEL)%')] private readonly string $model,
        #[Autowire('%kernel.project_dir%/resources/prompts/correcteur_media.md')] private readonly string $promptFile,
    ) {
    }

    public function review(string $text): string
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
                'system' => file_get_contents($this->promptFile),
                'messages' => [
                    ['role' => 'user', 'content' => "## TEXTE À RELIRE\n\n".$text],
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

        return trim($out);
    }
}
