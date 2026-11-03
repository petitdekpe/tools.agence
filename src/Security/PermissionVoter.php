<?php

namespace App\Security;

use App\Entity\User;
use App\Service\PermissionManager;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** Rend les permissions du catalogue utilisables avec #[IsGranted] et is_granted(). */
class PermissionVoter extends Voter
{
    public function __construct(private readonly PermissionManager $permissions)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return Permission::exists($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $this->permissions->has($user, $attribute);
    }
}
