<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AccountProvisioner;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Récupération d'un compte dont l'authentificateur n'est plus disponible : un lien à usage unique part vers l'e-mail du compte,
 * puis un mot de passe temporaire pour réinscrire Google Authenticator. La boîte e-mail fait office de second facteur de secours.
 */
class RecoveryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountProvisioner $accounts,
        private readonly ActivityLogger $activity,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/login/recovery', name: 'app_recovery_request', methods: ['GET', 'POST'])]
    public function request(Request $request, #[Autowire(service: 'limiter.account_recovery')] RateLimiterFactory $limiter): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $sent = false;
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('recovery_request', (string) $request->request->get('_token'))) {
                $error = 'Jeton de sécurité invalide, rechargez la page.';
            } elseif (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $error = 'Trop de demandes. Réessayez dans quelques minutes.';
            } else {
                $this->sendLink(mb_strtolower(trim((string) $request->request->get('email'))));
                $sent = true; // même réponse que le compte existe ou non
            }
        }

        return $this->render('security/recovery_request.html.twig', ['sent' => $sent, 'error' => $error, 'minutes' => AccountProvisioner::RECOVERY_VALID_MINUTES]);
    }

    /** GET affiche seulement la confirmation (un antivirus qui ouvre le lien ne doit rien consommer) ; POST réinitialise. */
    #[Route('/login/recovery/{token}', name: 'app_recovery_confirm', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function confirm(Request $request, string $token): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = $this->accounts->findByRecoveryToken($token);
        if (!$user) {
            return $this->render('security/recovery_confirm.html.twig', ['user' => null, 'error' => null], new Response(status: Response::HTTP_GONE));
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('recovery_confirm', (string) $request->request->get('_token'))) {
                $error = 'Jeton de sécurité invalide, rechargez la page.';
            } else {
                try {
                    $this->accounts->resetTwoFactor($user, 'recovery');
                    $this->em->flush();
                    $this->activity->log('auth.2fa_reset', ['id' => $user->getId(), 'email' => $user->getEmail(), 'via' => 'lien de récupération'], $user);
                    $this->addFlash('success', 'Authentificateur réinitialisé. Un mot de passe temporaire vient de vous être envoyé par e-mail.');

                    return $this->redirectToRoute('app_login');
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error('Envoi d\'e-mail de récupération impossible : '.$e->getMessage());
                    $error = 'L\'e-mail n\'a pas pu être envoyé. Rien n\'a été modifié : réessayez dans un instant.';
                }
            }
        }

        return $this->render('security/recovery_confirm.html.twig', ['user' => $user, 'error' => $error]);
    }

    private function sendLink(string $email): void
    {
        $user = User::isAllowedEmail($email) ? $this->em->getRepository(User::class)->findOneBy(['email' => $email]) : null;
        // Sans authentificateur inscrit, le mot de passe temporaire suffit déjà : un administrateur peut le renvoyer.
        if (!$user || !$user->isActive() || !$user->hasTwoFactor()) {
            return;
        }

        try {
            $this->accounts->sendRecoveryLink($user);
            $this->activity->log('auth.recovery_requested', [], $user);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Envoi du lien de récupération impossible : '.$e->getMessage());
        }
    }
}
