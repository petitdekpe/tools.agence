<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\PublicationRepository;
use App\Security\Permission;
use App\Service\ActivityLogger;
use App\Service\CalendarFeed;
use App\Service\PermissionManager;
use App\Service\SiteSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Abonnement du calendrier éditorial à un agenda externe (Google Agenda, Apple Calendrier, Outlook). L'agenda va chercher
 * lui-même un flux .ics sans session : l'adresse porte un jeton secret propre à chaque utilisateur, qu'il peut régénérer
 * (l'ancienne adresse cesse alors de fonctionner) ou désactiver.
 */
class CalendarSyncController extends AbstractController
{
    #[IsGranted(Permission::CALENDRIER_VIEW)]
    #[Route('/calendrier/synchronisation', name: 'app_calendrier_sync', methods: 'GET')]
    public function index(): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $feedUrl = $user->getCalendarToken()
            ? $this->generateUrl('app_calendrier_ical', ['token' => $user->getCalendarToken()], UrlGeneratorInterface::ABSOLUTE_URL)
            : null;

        return $this->render('calendrier/sync.html.twig', [
            'feedUrl' => $feedUrl,
            'webcalUrl' => $feedUrl ? preg_replace('#^https?://#i', 'webcal://', $feedUrl) : null,
            'googleUrl' => $feedUrl ? 'https://calendar.google.com/calendar/r?cid='.rawurlencode((string) preg_replace('#^https?://#i', 'webcal://', $feedUrl)) : null,
            'local' => $feedUrl && preg_match('#^https?://(localhost|127\.|192\.168\.|10\.|[^/]+\.(local|test|localhost)(:|/))#i', $feedUrl),
        ]);
    }

    /** Crée, régénère ou désactive l'adresse d'abonnement. */
    #[IsGranted(Permission::CALENDRIER_VIEW)]
    #[Route('/calendrier/synchronisation', name: 'app_calendrier_sync_update', methods: 'POST')]
    public function update(Request $request, EntityManagerInterface $em, ActivityLogger $activity): Response
    {
        if (!$this->isCsrfTokenValid('calendrier_sync', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $user = $this->getUser();
        \assert($user instanceof User);

        if ('revoke' === $request->request->get('action')) {
            $user->setCalendarToken(null);
            $this->addFlash('success', 'Abonnement désactivé : l\'ancienne adresse ne fonctionne plus.');
            $event = 'revoque';
        } else {
            $event = $user->getCalendarToken() ? 'regenere' : 'active';
            $user->setCalendarToken(bin2hex(random_bytes(32)));
            $this->addFlash('success', 'regenere' === $event ? 'Nouvelle adresse créée : remplacez l\'ancienne dans vos agendas.' : 'Abonnement activé.');
        }
        $em->flush();
        $activity->log('publication.sync', ['action' => $event]);

        return $this->redirectToRoute('app_calendrier_sync');
    }

    /** Le flux, sans session : l'accès tient au jeton, et les droits sur le calendrier de son propriétaire sont revérifiés à chaque lecture. */
    #[Route('/calendrier/ical/{token}.ics', name: 'app_calendrier_ical', methods: 'GET', requirements: ['token' => '[a-f0-9]{64}'])]
    public function feed(
        string $token,
        Request $request,
        EntityManagerInterface $em,
        PermissionManager $permissions,
        PublicationRepository $publications,
        CalendarFeed $feed,
        SiteSettings $site,
    ): Response {
        $user = $em->getRepository(User::class)->findOneBy(['calendarToken' => $token]);
        if (!$user || !$user->isActive() || !$permissions->has($user, Permission::CALENDRIER_VIEW)) {
            throw $this->createNotFoundException();
        }

        $today = new \DateTimeImmutable('today');
        $body = $feed->render(
            $publications->forFeed($today->modify('-60 days'), $today->modify('+366 days')),
            'Calendrier éditorial · '.$site->get()->getHeaderTitle(),
            $request->getHost(),
            fn ($publication): string => $this->generateUrl('app_publication_show', ['id' => $publication->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        );

        return new Response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="calendrier-editorial.ics"',
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
