<?php

namespace App\Tests\Controller;

use App\Entity\Client;
use App\Entity\Publication;
use App\Entity\User;
use App\Repository\PublicationRepository;
use App\Security\Permission;
use App\Tests\AppWebTestCase;

/** Calendrier éditorial : planification, circuit de validation, grille mensuelle, filtres, permissions. */
class PublicationTest extends AppWebTestCase
{
    public function testUnePublicationSePlanifieAvecSesDetails(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $client = $this->client('Société Alpha');

        $crawler = $this->browser->request('GET', '/calendrier/nouvelle?date=2026-11-12');
        self::assertResponseIsSuccessful();
        self::assertSame('2026-11-12', $crawler->filter('input[name="publication[datePublication]"]')->attr('value'), 'la date du jour cliqué est reprise');
        $this->browser->submit($crawler->selectButton('Planifier')->form([
            'publication[titre]' => 'Teaser saison 2',
            'publication[client]' => (string) $client->getId(),
            'publication[canal]' => 'instagram',
            'publication[format]' => 'video',
            'publication[heure]' => '09:30',
            'publication[statut]' => Publication::REDACTION,
            'publication[contenu]' => 'Bientôt, la suite.',
            'publication[lien]' => 'https://instagram.com/p/abc',
        ]));
        self::assertResponseRedirects();

        $this->em->clear();
        $p = $this->em->getRepository(Publication::class)->findOneBy([]);
        self::assertSame('Teaser saison 2', $p->getTitre());
        self::assertSame('Société Alpha', $p->getClient()?->getName());
        self::assertSame('09:30', $p->getHeure()?->format('H:i'));
        self::assertSame('2026-11-12', $p->getDatePublication()?->format('Y-m-d'));
        self::assertSame('agent@procom.pro', $p->getCreatedBy()?->getEmail());
    }

    public function testLesChampsObligatoiresEtLeLienSontControles(): void
    {
        $this->login($this->user(User::ROLE_AGENT));

        $crawler = $this->browser->request('GET', '/calendrier/nouvelle');
        $this->browser->submit($crawler->selectButton('Planifier')->form(['publication[titre]' => '', 'publication[lien]' => 'pas un lien']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le titre est obligatoire.');
        self::assertSelectorTextContains('body', 'Lien invalide');
    }

    public function testSeulQuiValidePeutPasserUnePublicationAValide(): void
    {
        $p = $this->publication('Post du lundi', '2026-11-09', Publication::RELECTURE);

        // Un rédacteur fait avancer, mais ne voit pas « validé » et ne peut pas le forcer.
        $this->login($this->user(User::ROLE_AGENT));
        $crawler = $this->browser->request('GET', '/calendrier/'.$p->getId());
        self::assertCount(0, $crawler->filter('select[name="statut"] option[value="valide"]'));
        $this->browser->submit($crawler->selectButton('Mettre à jour')->form(['statut' => Publication::REDACTION]));
        self::assertSame(Publication::REDACTION, $this->fresh(Publication::class, $p->getId())->getStatut());

        $token = $crawler->filter('form[action$="/statut"] input[name=_token]')->attr('value');
        $this->browser->request('POST', '/calendrier/'.$p->getId().'/statut', ['_token' => $token, 'statut' => Publication::VALIDE]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(Publication::REDACTION, $this->fresh(Publication::class, $p->getId())->getStatut());

        // La direction valide.
        $this->login($this->user(User::ROLE_DIRECTION));
        $crawler = $this->browser->request('GET', '/calendrier/'.$p->getId());
        self::assertCount(1, $crawler->filter('select[name="statut"] option[value="valide"]'));
        $this->browser->submit($crawler->selectButton('Mettre à jour')->form(['statut' => Publication::VALIDE]));
        self::assertSame(Publication::VALIDE, $this->fresh(Publication::class, $p->getId())->getStatut());
    }

    public function testUnStatutInconnuEstRefuse(): void
    {
        $p = $this->publication('Post', '2026-11-09');
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/calendrier/'.$p->getId());
        $token = $crawler->filter('form[action$="/statut"] input[name=_token]')->attr('value');
        $this->browser->request('POST', '/calendrier/'.$p->getId().'/statut', ['_token' => $token, 'statut' => 'nimporte']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testLaGrilleDuMoisPlaceChaquePublicationDansSonJour(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $this->publication('Lundi', '2026-11-02');
        $this->publication('Mardi A', '2026-11-03');
        $this->publication('Mardi B', '2026-11-03');
        $this->publication('Décembre', '2026-12-15');

        $crawler = $this->browser->request('GET', '/calendrier?mois=2026-11');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cal-month', 'novembre 2026');
        self::assertCount(3, $crawler->filter('.pchip'), 'seules les publications du mois (et de ses semaines affichées) apparaissent');
        self::assertCount(7 * 6, $crawler->filter('.pgrid-day'), 'novembre 2026 commence un dimanche : six semaines, du lundi 26 octobre au dimanche 6 décembre');
        self::assertSame(1, $crawler->filter('.pgrid-day')->reduce(static fn ($cell): bool => 2 === $cell->filter('.pchip')->count())->count(), 'les deux publications du mardi sont dans la même case');
        self::assertSelectorTextContains('.pgrid', 'Mardi A');

        $crawler = $this->browser->request('GET', '/calendrier?mois=2026-12');
        self::assertSelectorTextContains('.pgrid', 'Décembre');
        self::assertSelectorTextNotContains('.pgrid', 'Mardi A');

        $this->browser->request('GET', '/calendrier?mois=pas-un-mois');
        self::assertResponseIsSuccessful('un mois invalide retombe sur le mois courant');
    }

    public function testUneJourneeChargeeAfficheLesAutresEnLien(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
            $this->publication('Publication '.$letter, '2026-11-18');
        }

        $crawler = $this->browser->request('GET', '/calendrier?mois=2026-11');
        self::assertCount(3, $crawler->filter('.pchip'));
        self::assertSelectorTextContains('.pgrid-more', '+ 2 autres');
        self::assertStringContainsString('from=2026-11-18', $crawler->filter('.pgrid-more')->attr('href'));
    }

    public function testLesFiltresRestreignentLaGrilleEtLaListe(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $alpha = $this->client('Alpha');
        $beta = $this->client('Beta');
        $this->publication('Alpha post', '2026-11-04', Publication::IDEE, $alpha, 'facebook');
        $this->publication('Beta vidéo', '2026-11-05', Publication::PUBLIE, $beta, 'youtube');
        $this->publication('Interne', '2026-11-06', Publication::VALIDE, null, 'linkedin');

        $crawler = $this->browser->request('GET', '/calendrier?mois=2026-11&client='.$beta->getId());
        self::assertCount(1, $crawler->filter('.pchip'));
        self::assertSelectorTextContains('.pgrid', 'Beta vidéo');

        $crawler = $this->browser->request('GET', '/calendrier/liste');
        self::assertCount(3, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/calendrier/liste?statut=encours');
        self::assertCount(2, $crawler->filter('.enc-table tbody tr'), 'idée et validé ne sont pas encore sortis');
        $crawler = $this->browser->request('GET', '/calendrier/liste?canal=youtube');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/calendrier/liste?q=alpha');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/calendrier/liste?from=2026-11-05&to=2026-11-05');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        self::assertSelectorTextContains('.enc-table tbody', 'Beta vidéo');

        $this->browser->request('GET', '/calendrier/liste?statut=zzz&canal=zzz&client=abc&sort=zzz&page=abc');
        self::assertResponseIsSuccessful('des paramètres invalides retombent sur les valeurs par défaut');
    }

    public function testLesRetardsEtLesChiffresClesSeCalculent(): void
    {
        $this->publication('En retard', '-3 days', Publication::REDACTION);
        $this->publication('Déjà sortie', '-3 days', Publication::PUBLIE);
        $this->publication('Annulée', '-3 days', Publication::ANNULE);
        $this->publication('À relire', '+2 days', Publication::RELECTURE);
        $late = $this->publication('Validée en retard', '-1 day', Publication::VALIDE);

        self::assertTrue($late->isLate());
        $board = static::getContainer()->get(PublicationRepository::class)->dashboard();
        self::assertSame(1, $board['toReview']);
        self::assertSame(2, $board['late'], 'seules les publications pas encore sorties, à date passée');
    }

    public function testLesPermissionsGouvernentChaqueAction(): void
    {
        $p = $this->publication('Post', '2026-11-09');

        $this->login($this->user($this->customRole('Observateur')->getCode(), 'obs@procom.pro'));
        foreach (['/calendrier', '/calendrier/liste', '/calendrier/'.$p->getId(), '/calendrier/nouvelle'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->login($this->user($this->customRole('Lecteur', [Permission::CALENDRIER_VIEW])->getCode(), 'lecteur@procom.pro'));
        $this->browser->request('GET', '/calendrier');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href*="/calendrier/nouvelle"]');
        $this->browser->request('GET', '/calendrier/nouvelle');
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('GET', '/calendrier/'.$p->getId());
        self::assertSelectorNotExists('form[action$="/statut"]');
        self::assertSelectorNotExists('form[action$="/supprimer"]');

        $this->login($this->user(User::ROLE_DIRECTION));
        $this->browser->request('POST', '/calendrier/'.$p->getId().'/supprimer');
        self::assertResponseStatusCodeSame(403, 'la direction valide mais ne supprime pas');
    }

    public function testLaSuppressionEstLogique(): void
    {
        $p = $this->publication('Post', '2026-11-09');
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/calendrier/'.$p->getId());
        $this->browser->submit($crawler->filter('form[action$="/supprimer"]')->form());
        self::assertResponseRedirects();

        self::assertTrue($this->fresh(Publication::class, $p->getId())->isDeleted());
        $this->browser->request('GET', '/calendrier/'.$p->getId());
        self::assertResponseStatusCodeSame(404);
        $crawler = $this->browser->request('GET', '/calendrier?mois=2026-11');
        self::assertCount(0, $crawler->filter('.pchip'));
    }

    // ---------- fabriques ----------

    private function client(string $name): Client
    {
        $client = (new Client())->setName($name);
        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    private function publication(string $titre, string $date, string $statut = Publication::IDEE, ?Client $client = null, string $canal = 'facebook'): Publication
    {
        $p = (new Publication())->setTitre($titre)->setDatePublication(new \DateTimeImmutable($date))->setStatut($statut)->setClient($client)->setCanal($canal);
        $this->em->persist($p);
        $this->em->flush();

        return $p;
    }
}
