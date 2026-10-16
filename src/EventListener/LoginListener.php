<?php

namespace App\EventListener;

use App\Entity\User;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** Date de dernière connexion et journal d'authentification. */
class LoginListener
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ActivityLogger $activity)
    {
    }

    #[AsEventListener]
    public function onSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }
        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->activity->log('auth.login', ['first_login' => !$user->hasTwoFactor()], $user);
    }

    #[AsEventListener]
    public function onFailure(LoginFailureEvent $event): void
    {
        $email = mb_substr(mb_strtolower(trim((string) $event->getRequest()->request->get('email'))), 0, 180);
        $this->activity->log('auth.login_failed', ['reason' => $event->getException()->getMessageKey()], null, $email ?: null);
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof User) {
            $this->activity->log('auth.logout', [], $user);
        }
    }
}
