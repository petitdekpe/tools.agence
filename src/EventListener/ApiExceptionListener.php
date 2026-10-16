<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Renvoie les erreurs HTTP de l'API du correcteur sous forme {"error": "..."}. */
#[AsEventListener]
class ApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/correcteur-media/api/') || !$e instanceof HttpExceptionInterface) {
            return;
        }

        $message = match ($e->getStatusCode()) {
            404 => 'Correction introuvable.',
            default => $e->getMessage() ?: 'Erreur inattendue.',
        };
        $event->setResponse(new JsonResponse(['error' => $message], $e->getStatusCode()));
    }
}
