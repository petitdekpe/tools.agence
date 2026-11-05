<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\AppWebTestCase;

/** Application installable : manifeste public, fichiers du service worker et éléments mobiles de la coque. */
class PwaTest extends AppWebTestCase
{
    private const PUBLIC_DIR = __DIR__.'/../../public';

    public function testLeManifesteEstPublicEtComplet(): void
    {
        // Sans session : le navigateur lit le manifeste avant toute connexion.
        $this->browser->request('GET', '/manifest');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/manifest+json', $this->browser->getResponse()->headers->get('Content-Type'));

        $manifest = json_decode($this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('PROCOM Tools', $manifest['name']);
        self::assertSame('standalone', $manifest['display']);
        self::assertSame('/', $manifest['scope']);
        self::assertSame('fr', $manifest['lang']);

        $purposes = [];
        foreach ($manifest['icons'] as $icon) {
            self::assertFileExists(self::PUBLIC_DIR.$icon['src'], $icon['src']);
            [$w, $h] = getimagesize(self::PUBLIC_DIR.$icon['src']);
            self::assertSame($icon['sizes'], $w.'x'.$h, 'la taille déclarée est la taille réelle de '.$icon['src']);
            $purposes[$icon['purpose']][] = $icon['sizes'];
        }
        self::assertContains('192x192', $purposes['any']);
        self::assertContains('512x512', $purposes['any']);
        self::assertContains('512x512', $purposes['maskable']);
    }

    public function testLeServiceWorkerEtLaPageHorsLigneExistent(): void
    {
        self::assertFileExists(self::PUBLIC_DIR.'/offline.html');
        self::assertFileExists(self::PUBLIC_DIR.'/pwa/apple-touch-icon.png');

        $sw = (string) file_get_contents(self::PUBLIC_DIR.'/sw.js');
        // Rien de sensible ne doit être gardé hors ligne.
        foreach (['/login', '/logout', '/admin', '/personnel/documents/', '/calendrier/ical/', '/liens/', '/manifest'] as $path) {
            self::assertStringContainsString("'$path", $sw, "$path doit rester hors du cache");
        }
        self::assertStringContainsString("'clear-pages'", $sw);
        // Chaque adresse précachée à l'installation existe.
        foreach (['/offline.html', '/pwa/icon-192.png', '/pwa/icon-512.png', '/pwa/apple-touch-icon.png'] as $path) {
            self::assertFileExists(self::PUBLIC_DIR.$path);
        }
    }

    public function testLaPageDeConnexionDeclareLApplication(): void
    {
        $crawler = $this->browser->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSame('/manifest', $crawler->filter('link[rel=manifest]')->attr('href'));
        self::assertSame('/pwa/apple-touch-icon.png', $crawler->filter('link[rel=apple-touch-icon]')->attr('href'));
        self::assertStringContainsString('viewport-fit=cover', $crawler->filter('meta[name=viewport]')->attr('content'));
        self::assertSame('pwa tables', $crawler->filter('body')->attr('data-controller'));
    }

    public function testLaCoqueMobileOffreBarreDOngletsEtFeuilleCreer(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $crawler = $this->browser->request('GET', '/');
        self::assertResponseIsSuccessful();

        $tabbar = $crawler->filter('nav.tabbar');
        self::assertCount(1, $tabbar);
        self::assertGreaterThan(0, $tabbar->filter('a[href="/"]')->count());
        self::assertGreaterThan(0, $tabbar->filter('a[href="/profil"]')->count());
        self::assertCount(1, $tabbar->filter('button[data-action="shell#toggleSheet"]'));

        // La feuille reprend les actions de création accessibles au rôle.
        self::assertGreaterThan(3, $crawler->filter('.sheet .sheet-item')->count());
        self::assertGreaterThan(0, $crawler->filter('.sheet .sheet-item[href="/encaissements/nouveau"]')->count());

        // Déconnexion : les copies de pages gardées hors ligne sont effacées.
        foreach ($crawler->filter('a[href="/logout"]') as $link) {
            if (str_contains($link->getAttribute('class'), 'tab')) {
                continue;
            }
            self::assertSame('pwa#logout', $link->getAttribute('data-action'));
        }
        // Bouton d'installation caché tant que le navigateur ne la propose pas.
        self::assertCount(0, $crawler->filter('[data-pwa-target=install]:not([hidden])'));
        self::assertNotNull($crawler->filter('[data-pwa-target=banner]')->attr('hidden'));
    }

    public function testUnCompteSansActionDeCreationNAPasDeBoutonPlus(): void
    {
        $this->login($this->user(User::ROLE_MATERIEL));
        $crawler = $this->browser->request('GET', '/');
        // Le rôle Matériel peut créer du matériel : le bouton existe ; un rôle vide n'en a pas.
        self::assertCount(1, $crawler->filter('.tabbar .tab-create'));

        $role = $this->customRole('Lecture seule', []);
        $user = (new User())->setEmail('lecture@procom.pro')->setFullName('Lecture')->setRole($role)->setPassword('x')->setTotpSecret('x');
        $this->em->persist($user);
        $this->em->flush();
        $this->login($user);
        $crawler = $this->browser->request('GET', '/');
        self::assertCount(0, $crawler->filter('.tabbar .tab-create'));
        self::assertCount(0, $crawler->filter('.sheet'));
    }
}
