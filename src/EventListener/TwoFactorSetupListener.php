<?php

namespace App\EventListener;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Tant que l'authentificateur n'est pas inscrit, tout est redirigé vers l'inscription. */
#[AsEventListener(priority: 0)]
class TwoFactorSetupListener
{
    private const ALLOWED_ROUTES = ['app_2fa_setup', 'app_logout'];

    public function __construct(private readonly Security $security, private readonly UrlGeneratorInterface $urls)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || str_starts_with($request->getPathInfo(), '/_')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->hasTwoFactor() || \in_array($request->attributes->get('_route'), self::ALLOWED_ROUTES, true)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_2fa_setup')));
    }
}
