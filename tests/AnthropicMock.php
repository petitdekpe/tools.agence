<?php

namespace App\Tests;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Remplace l'API Anthropic en test (framework.http_client.mock_response_factory) : aucune requête ne sort,
 * et les requêtes reçues restent consultables pour vérifier ce qui serait envoyé au modèle.
 */
final class AnthropicMock
{
    public const REPLY = "Verdict : publiable après corrections — 2 corrections, 0 point à vérifier.\n\n**REMARQUES**\n\n**1. 🔴 « Sellopas,votre » → « Sellopas, votre ».**\nEspace manquante après la virgule.\n\n**2. 🟠 « cliquez ici » → supprimé.**\nRemplissage de bannière publicitaire. Le CTA devient une phrase d'action directe.";

    /** @var list<array<string, mixed>> corps JSON des requêtes reçues, dans l'ordre */
    public static array $requests = [];
    public static string $reply = self::REPLY;
    public static int $status = 200;
    /** @var array<string, int> */
    public static array $usage = ['input_tokens' => 1200, 'output_tokens' => 300, 'cache_read_input_tokens' => 4000, 'cache_creation_input_tokens' => 0];

    public static function reset(): void
    {
        self::$requests = [];
        self::$reply = self::REPLY;
        self::$status = 200;
        self::$usage = ['input_tokens' => 1200, 'output_tokens' => 300, 'cache_read_input_tokens' => 4000, 'cache_creation_input_tokens' => 0];
    }

    /** Le message utilisateur de la dernière requête. */
    public static function lastUserMessage(): string
    {
        return (string) (end(self::$requests)['messages'][0]['content'] ?? '');
    }

    /** Les blocs du prompt système de la dernière requête. */
    public static function lastSystem(): array
    {
        return (array) (end(self::$requests)['system'] ?? []);
    }

    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        self::$requests[] = json_decode((string) ($options['body'] ?? ''), true) ?: [];

        $body = 200 === self::$status
            ? ['model' => 'claude-test', 'content' => [['type' => 'text', 'text' => self::$reply]], 'usage' => self::$usage]
            : ['error' => ['message' => 'Surcharge de l\'API.']];

        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => self::$status, 'response_headers' => ['content-type: application/json']]);
    }
}
