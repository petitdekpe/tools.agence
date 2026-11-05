<?php

namespace App\Tests\Controller;

use App\Entity\Publication;
use App\Entity\User;
use App\Tests\AppWebTestCase;

/** Abonnement du calendrier éditorial à un agenda externe : adresse secrète par utilisateur, flux .ics sans session. */
class CalendarSyncTest extends AppWebTestCase
{
    public function testActiverPuisLireLeFluxSansSession(): void
    {
        $this->publication('Post de rentrée', '+3 days');
        $user = $this->user(User::ROLE_AGENT);
        $this->login($user);

        $crawler = $this->browser->request('GET', '/calendrier/synchronisation');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tabs .active', 'Synchroniser');
        self::assertSelectorNotExists('#feed-url');
        $this->browser->submit($crawler->selectButton('Activer mon abonnement')->form());
        self::assertResponseRedirects('/calendrier/synchronisation');

        $crawler = $this->browser->followRedirect();
        $url = $crawler->filter('#feed-url')->attr('value');
        self::assertMatchesRegularExpression('#/calendrier/ical/[a-f0-9]{64}\.ics$#', $url);
        self::assertStringContainsString('webcal://', $crawler->filter('.sync-actions a.btn-outline')->attr('href'));
        self::assertStringContainsString('calendar.google.com/calendar/r?cid=webcal', $crawler->filter('.sync-actions a.btn')->attr('href'));

        // L'agenda externe n'a pas de session : le jeton suffit.
        $feed = $this->anonymousClient();
        $feed->request('GET', parse_url($url, \PHP_URL_PATH));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/calendar', $feed->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('SUMMARY:Facebook · Post de rentrée', $feed->getResponse()->getContent());
    }

    public function testRegenererRevoqueLAncienneAdresse(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $crawler = $this->browser->request('GET', '/calendrier/synchronisation');
        $this->browser->submit($crawler->selectButton('Activer mon abonnement')->form());
        $old = $this->tokenOf('agent@procom.pro');

        $crawler = $this->browser->request('GET', '/calendrier/synchronisation');
        $this->browser->submit($crawler->selectButton('Créer une nouvelle adresse')->form());
        $new = $this->tokenOf('agent@procom.pro');
        self::assertNotSame($old, $new);

        $this->anonymousClient()->request('GET', "/calendrier/ical/$old.ics");
        self::assertResponseStatusCodeSame(404);
        $this->anonymousClient()->request('GET', "/calendrier/ical/$new.ics");
        self::assertResponseIsSuccessful();

        // Le client anonyme a vidé les cookies : on se reconnecte pour désactiver.
        $this->login($this->em->getRepository(User::class)->findOneBy(['email' => 'agent@procom.pro']));
        $crawler = $this->browser->request('GET', '/calendrier/synchronisation');
        $this->browser->submit($crawler->selectButton('Désactiver l\'abonnement')->form());
        self::assertNull($this->tokenOf('agent@procom.pro'));
        $this->anonymousClient()->request('GET', "/calendrier/ical/$new.ics");
        self::assertResponseStatusCodeSame(404);
    }

    public function testJetonInconnuEtDroitsReverifies(): void
    {
        $client = $this->anonymousClient();
        $client->request('GET', '/calendrier/ical/'.str_repeat('a', 64).'.ics');
        self::assertResponseStatusCodeSame(404);

        // Un rôle sans accès au calendrier ne peut ni s'abonner, ni garder un flux actif.
        $role = $this->customRole('Externe', []);
        $user = (new User())->setEmail('externe@procom.pro')->setFullName('Externe')->setRole($role)->setPassword('x')->setTotpSecret('x')->setCalendarToken(str_repeat('b', 64));
        $this->em->persist($user);
        $this->em->flush();
        $this->login($user);
        $this->browser->request('GET', '/calendrier/synchronisation');
        self::assertResponseStatusCodeSame(403);
        $this->anonymousClient()->request('GET', '/calendrier/ical/'.str_repeat('b', 64).'.ics');
        self::assertResponseStatusCodeSame(404);
    }

    private function publication(string $titre, string $date): Publication
    {
        $p = (new Publication())->setTitre($titre)->setDatePublication(new \DateTimeImmutable($date))->setStatut(Publication::REDACTION);
        $this->em->persist($p);
        $this->em->flush();

        return $p;
    }

    private function tokenOf(string $email): ?string
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email])->getCalendarToken();
    }

    /** Un navigateur sans session, comme l'est Google Agenda. */
    private function anonymousClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $this->browser->getCookieJar()->clear();

        return $this->browser;
    }
}
