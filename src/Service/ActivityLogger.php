<?php

namespace App\Service;

use App\Entity\ActivityLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/** Enregistre qui a fait quoi, quand et depuis où. */
class ActivityLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * @param User|null $user  utilisateur concerné ; par défaut l'utilisateur connecté
     * @param string|null $email e-mail à enregistrer quand il n'y a pas de compte (tentative de connexion)
     */
    public function log(string $action, array $details = [], ?User $user = null, ?string $email = null): void
    {
        $user ??= $this->security->getUser() instanceof User ? $this->security->getUser() : null;
        $request = $this->requests->getCurrentRequest();

        $entry = (new ActivityLog())
            ->setAction($action)
            ->setUser($user)
            ->setUserEmail($user?->getEmail() ?? $email)
            ->setDetails($details)
            ->setIp($request?->getClientIp())
            ->setUserAgent($request?->headers->get('User-Agent'));

        $this->em->persist($entry);
        $this->em->flush();
    }
}
