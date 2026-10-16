<?php

namespace App\Security;

use App\Entity\User;
use App\Service\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Connexion : e-mail + code Google Authenticator (obligatoire).
 * Le mot de passe n'est demandé qu'à la demande, et pour la toute première connexion,
 * tant que l'authentificateur n'est pas inscrit.
 */
class LoginAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TotpService $totp,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $email = mb_strtolower(trim((string) $request->request->get('email')));
        $password = (string) $request->request->get('password');
        $code = (string) $request->request->get('code');
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        // Message volontairement identique pour un domaine refusé, un compte inconnu ou un mauvais code.
        if (!User::isAllowedEmail($email)) {
            throw new BadCredentialsException();
        }

        return new Passport(
            new UserBadge($email),
            new CustomCredentials(fn (string $credentials, UserInterface $user): bool => $this->check($user, $password, $code), $email),
            [new CsrfTokenBadge('authenticate', (string) $request->request->get('_csrf_token'))],
        );
    }

    private function check(UserInterface $user, string $password, string $code): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        $passwordOk = '' !== $password && $this->hasher->isPasswordValid($user, $password);

        if ($user->hasTwoFactor()) {
            // Mot de passe saisi (à la demande) : il doit être correct, en plus du code.
            if ('' !== $password && !$passwordOk) {
                return false;
            }
            $ok = $this->totp->verifyForUser($user, $code);
            $this->em->flush(); // mémorise le code utilisé

            return $ok && $this->assertActive($user);
        }

        // Première connexion : le mot de passe initial suffit, l'authentificateur est inscrit ensuite.
        return $passwordOk && $this->assertActive($user);
    }

    private function assertActive(User $user): bool
    {
        if (!$user->isActive()) {
            throw new CustomUserMessageAuthenticationException('Ce compte est désactivé.');
        }

        return true;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if ($target = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($target);
        }

        return new RedirectResponse($this->urls->generate('app_home'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urls->generate('app_login');
    }
}
