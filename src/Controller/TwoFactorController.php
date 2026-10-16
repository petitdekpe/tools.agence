<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\ActivityLogger;
use App\Service\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Inscription obligatoire de Google Authenticator à la première connexion. */
class TwoFactorController extends AbstractController
{
    private const SESSION_KEY = 'totp_pending_secret';

    #[Route('/2fa/setup', name: 'app_2fa_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request, TotpService $totp, EntityManagerInterface $em, ActivityLogger $activity): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($user->hasTwoFactor()) {
            return $this->redirectToRoute('app_home');
        }

        $session = $request->getSession();
        $secret = $session->get(self::SESSION_KEY) ?? $totp->generateSecret();
        $session->set(self::SESSION_KEY, $secret);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('totp_setup', (string) $request->request->get('_token'))) {
                $error = 'Jeton de sécurité invalide, rechargez la page.';
            } elseif (!$totp->verifyPlain($secret, preg_replace('/\s+/', '', (string) $request->request->get('code')))) {
                $error = 'Code incorrect. Vérifiez l\'heure de votre téléphone et réessayez.';
            } else {
                $user->setTotpSecret($totp->encrypt($secret));
                $user->rememberTotpCode(preg_replace('/\s+/', '', (string) $request->request->get('code')));
                $em->flush();
                $session->remove(self::SESSION_KEY);
                $activity->log('auth.2fa_enabled');
                $this->addFlash('success', 'Google Authenticator est activé. Utilisez-le à chaque connexion.');

                return $this->redirectToRoute('app_home');
            }
        }

        $uri = $totp->provisioningUri($secret, $user->getEmail());

        return $this->render('security/two_factor_setup.html.twig', [
            'qr' => $totp->qrDataUri($uri),
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'error' => $error,
        ]);
    }
}
