<?php

namespace App\Tests\Controller;

use App\Entity\Client;
use App\Entity\Projet;
use App\Entity\Tache;
use App\Entity\User;
use App\Repository\ProjetRepository;
use App\Security\Permission;
use App\Service\KanbanOrder;
use App\Tests\AppWebTestCase;

/** Projets et tâches : tableaux Kanban, déplacement des cartes, avancement, liste, permissions. */
class ProjetTest extends AppWebTestCase
{
    public function testUnProjetInterneSeCreeSansClientEtArriveEnColonneALancer(): void
    {
        $this->login($this->user(User::ROLE_AGENT));

        $crawler = $this->browser->request('GET', '/projets/nouveau');
        self::assertResponseIsSuccessful();
        $this->browser->submit($crawler->selectButton('Créer le projet')->form(['projet[nom]' => 'Refonte de l\'intranet']));
        self::assertResponseRedirects();

        $this->em->clear();
        $projet = $this->em->getRepository(Projet::class)->findOneBy([]);
        self::assertSame('Refonte de l\'intranet', $projet->getNom());
        self::assertNull($projet->getClient());
        self::assertSame(Projet::A_LANCER, $projet->getStatut());
        self::assertSame('agent@procom.pro', $projet->getCreatedBy()?->getEmail());

        $crawler = $this->browser->request('GET', '/projets');
        self::assertCount(1, $crawler->filter('.kb-col:nth-child(1) .kb-card'));
        self::assertSelectorTextContains('.kb-card', 'Projet interne');
    }

    public function testUnProjetPeutEtreRattacheAUnClient(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $client = $this->client('Société Alpha');

        $crawler = $this->browser->request('GET', '/projets/nouveau?statut=en_cours&client='.$client->getId());
        self::assertSame((string) $client->getId(), $crawler->filter('select[name="projet[client]"] option[selected]')->attr('value'));
        self::assertSame('en_cours', $crawler->filter('select[name="projet[statut]"] option[selected]')->attr('value'), 'la colonne d\'où l\'on vient est préremplie');
        $this->browser->submit($crawler->selectButton('Créer le projet')->form(['projet[nom]' => 'Campagne été']));

        $this->em->clear();
        $projet = $this->em->getRepository(Projet::class)->findOneBy([]);
        self::assertSame('Société Alpha', $projet->getClient()?->getName());
        self::assertSame(Projet::EN_COURS, $projet->getStatut());
    }

    public function testLesChampsObligatoiresEtLesDatesSontControles(): void
    {
        $this->login($this->user(User::ROLE_AGENT));

        $crawler = $this->browser->request('GET', '/projets/nouveau');
        $this->browser->submit($crawler->selectButton('Créer le projet')->form(['projet[nom]' => '', 'projet[dateDebut]' => '2026-06-10', 'projet[echeance]' => '2026-06-01']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le nom du projet est obligatoire.');
        self::assertSelectorTextContains('body', 'L\'échéance est antérieure à la date de début.');
    }

    public function testLeTableauRangeLesProjetsParColonneEtCacheLesAnnules(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $alpha = $this->client('Alpha');
        $this->projet('Lancé', Projet::A_LANCER);
        $this->projet('En route A', Projet::EN_COURS, $alpha);
        $this->projet('En route B', Projet::EN_COURS);
        $this->projet('Fini', Projet::TERMINE);
        $this->projet('Abandonné', Projet::ANNULE);

        $crawler = $this->browser->request('GET', '/projets');
        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('.kb-col'));
        self::assertCount(1, $crawler->filter('.kb-col:nth-child(1) .kb-card'));
        self::assertCount(2, $crawler->filter('.kb-col:nth-child(2) .kb-card'));
        self::assertCount(0, $crawler->filter('.kb-col:nth-child(3) .kb-card'));
        self::assertCount(1, $crawler->filter('.kb-col:nth-child(4) .kb-card'));
        self::assertSelectorTextNotContains('.kb', 'Abandonné');

        $crawler = $this->browser->request('GET', '/projets?client='.$alpha->getId());
        self::assertCount(1, $crawler->filter('.kb-card'));

        $crawler = $this->browser->request('GET', '/projets/liste?statut=annule');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        self::assertSelectorTextContains('.enc-table tbody', 'Abandonné');
    }

    public function testUneCarteDeProjetSeDeplaceEtLeServeurGardeLOrdre(): void
    {
        $a = $this->projet('A', Projet::EN_COURS, null, 0);
        $b = $this->projet('B', Projet::EN_COURS, null, 1);
        $c = $this->projet('C', Projet::EN_COURS, null, 2);
        $this->login($this->user(User::ROLE_AGENT));
        $token = $this->kanbanToken('/projets');

        $this->moveCard('/projets/'.$c->getId().'/deplacer', $token, 'en_cours', 0);
        self::assertResponseIsSuccessful();
        $column = static::getContainer()->get(ProjetRepository::class)->column(Projet::EN_COURS);
        self::assertSame(['C', 'A', 'B'], array_map(static fn (Projet $p): string => $p->getNom(), $column), 'C est passé en tête');

        $this->moveCard('/projets/'.$a->getId().'/deplacer', $token, 'termine', 0);
        $a = $this->fresh(Projet::class, $a->getId());
        self::assertSame(Projet::TERMINE, $a->getStatut());
        self::assertNotNull($a->getClosedAt(), 'la date de clôture suit le passage à terminé');

        $this->moveCard('/projets/'.$a->getId().'/deplacer', $token, 'en_pause', 0);
        self::assertNull($this->fresh(Projet::class, $a->getId())->getClosedAt(), 'rouvert : plus de date de clôture');
    }

    public function testUnDeplacementInvalideOuNonAutoriseEstRefuse(): void
    {
        $p = $this->projet('A', Projet::EN_COURS);
        $this->login($this->user(User::ROLE_AGENT));
        $token = $this->kanbanToken('/projets');

        $this->moveCard('/projets/'.$p->getId().'/deplacer', $token, 'nimporte', 0);
        self::assertResponseStatusCodeSame(404);

        $this->moveCard('/projets/'.$p->getId().'/deplacer', 'mauvais-jeton', 'termine', 0);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(Projet::EN_COURS, $this->fresh(Projet::class, $p->getId())->getStatut());

        $this->login($this->user($this->customRole('Lecteur', [Permission::PROJET_VIEW])->getCode(), 'lecteur@procom.pro'));
        $this->moveCard('/projets/'.$p->getId().'/deplacer', $token, 'termine', 0);
        self::assertResponseStatusCodeSame(403, 'la lecture seule ne déplace rien');
    }

    public function testChangerLeStatutParLeFormulaireMetLaCarteEnBasDeLaNouvelleColonne(): void
    {
        $this->projet('Déjà là', Projet::EN_COURS, null, 0);
        $p = $this->projet('Nouveau venu', Projet::A_LANCER, null, 0);
        $this->login($this->user(User::ROLE_AGENT));

        $crawler = $this->browser->request('GET', '/projets/'.$p->getId().'/modifier');
        $this->browser->submit($crawler->selectButton('Enregistrer les modifications')->form(['projet[statut]' => Projet::EN_COURS]));

        $column = static::getContainer()->get(ProjetRepository::class)->column(Projet::EN_COURS);
        self::assertSame(['Déjà là', 'Nouveau venu'], array_map(static fn (Projet $x): string => $x->getNom(), $column));
    }

    public function testLeRangDeLaCarteSeRenumerote(): void
    {
        $cards = [new Rang(0, 'a'), new Rang(1, 'b'), new Rang(2, 'c')];
        $incoming = new Rang(9, 'x');

        KanbanOrder::place($cards, $incoming, 1);
        $all = [$cards[0], $incoming, $cards[1], $cards[2]];
        self::assertSame([0, 1, 2, 3], array_map(static fn (Rang $r): int => $r->getPosition(), $all));

        KanbanOrder::place($all, $all[3], 0);
        self::assertSame(0, $all[3]->getPosition(), 'la carte déplacée passe en tête');
        self::assertSame(1, $all[0]->getPosition());

        $solo = new Rang(5, 'solo');
        KanbanOrder::place([], $solo, 99);
        self::assertSame(0, $solo->getPosition(), 'un rang hors limites est ramené à la fin');
    }

    public function testLesTachesSAjoutentSeDeplacentEtFontAvancerLeProjet(): void
    {
        $projet = $this->projet('Campagne', Projet::EN_COURS);
        $this->task($projet, 'Tourner les images', Tache::A_FAIRE);
        $this->login($this->user(User::ROLE_AGENT));

        $crawler = $this->browser->request('GET', '/projets/'.$projet->getId().'/taches/nouvelle?statut=en_cours');
        self::assertSame('en_cours', $crawler->filter('select[name="tache[statut]"] option[selected]')->attr('value'));
        $this->browser->submit($crawler->selectButton('Ajouter la carte')->form(['tache[titre]' => 'Écrire le brief', 'tache[priorite]' => 'haute']));
        self::assertResponseRedirects('/projets/'.$projet->getId());

        $crawler = $this->browser->request('GET', '/projets/'.$projet->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('.kb-col'));
        self::assertCount(1, $crawler->filter('.kb-col:nth-child(2) .kb-card'), 'la carte est dans « en cours »');
        self::assertSelectorTextContains('[data-kanban-progress-label]', '0 %');

        $this->em->clear();
        $brief = $this->em->getRepository(Tache::class)->findOneBy(['titre' => 'Écrire le brief']);
        $token = $crawler->filter('.kb')->attr('data-kanban-token-value');
        $this->moveCard('/projets/taches/'.$brief->getId().'/deplacer', $token, 'termine', 0);
        self::assertResponseIsSuccessful();

        $data = json_decode($this->browser->getResponse()->getContent(), true);
        self::assertSame(50, $data['progress'], 'une tâche sur deux est terminée');
        $brief = $this->fresh(Tache::class, $brief->getId());
        self::assertTrue($brief->isDone());
        self::assertNotNull($brief->getClosedAt());
        self::assertSame(50, $this->fresh(Projet::class, $projet->getId())->getProgress());
    }

    public function testUneTacheSeModifieEtSeSupprime(): void
    {
        $projet = $this->projet('Campagne', Projet::EN_COURS);
        $t = $this->task($projet, 'Brief', Tache::A_FAIRE);
        $this->login($this->user(User::ROLE_AGENT));

        $crawler = $this->browser->request('GET', '/projets/taches/'.$t->getId().'/modifier');
        $this->browser->submit($crawler->selectButton('Enregistrer les modifications')->form(['tache[titre]' => 'Brief validé', 'tache[priorite]' => 'basse']));
        self::assertSame('Brief validé', $this->fresh(Tache::class, $t->getId())->getTitre());

        $crawler = $this->browser->request('GET', '/projets/taches/'.$t->getId().'/modifier');
        $this->browser->submit($crawler->filter('form[action$="/supprimer"]')->form());
        self::assertResponseRedirects('/projets/'.$projet->getId());
        $this->em->clear();
        self::assertNull($this->em->getRepository(Tache::class)->find($t->getId()));
    }

    public function testLeTableauDesTachesNeSeDeplacePasEnLectureSeule(): void
    {
        $projet = $this->projet('Campagne', Projet::EN_COURS);
        $this->task($projet, 'Brief', Tache::A_FAIRE);

        $this->login($this->user($this->customRole('Lecteur', [Permission::PROJET_VIEW])->getCode(), 'lecteur@procom.pro'));
        $crawler = $this->browser->request('GET', '/projets/'.$projet->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.kb-card[draggable="true"]'));
        self::assertSelectorNotExists('a.kb-add');
        self::assertSelectorNotExists('a[href$="/modifier"]');

        $this->login($this->user(User::ROLE_AGENT));
        $crawler = $this->browser->request('GET', '/projets/'.$projet->getId());
        self::assertCount(1, $crawler->filter('.kb-card[draggable="true"]'));
        self::assertSelectorExists('a.kb-add');
    }

    public function testLeTableauDeBordCompteLesProjetsEtLesTachesEnRetard(): void
    {
        $late = $this->projet('En retard', Projet::EN_COURS);
        $late->setEcheance(new \DateTimeImmutable('-3 days'));
        $this->projet('À l\'heure', Projet::EN_COURS)->setEcheance(new \DateTimeImmutable('+10 days'));
        $closed = $this->projet('Fini en retard', Projet::TERMINE);
        $closed->setEcheance(new \DateTimeImmutable('-20 days'));
        $t1 = $this->task($late, 'Retard', Tache::A_FAIRE);
        $t1->setEcheance(new \DateTimeImmutable('-1 day'));
        $t2 = $this->task($late, 'Fait', Tache::TERMINE);
        $t2->setEcheance(new \DateTimeImmutable('-1 day'));
        $this->task($late, 'Sans échéance', Tache::EN_COURS);
        $this->em->flush();

        $board = static::getContainer()->get(ProjetRepository::class)->dashboard();
        self::assertSame(2, $board['inProgress']);
        self::assertSame(1, $board['late'], 'un projet terminé n\'est jamais en retard');
        self::assertSame(1, $board['lateTasks'], 'une tâche terminée n\'est jamais en retard');
        self::assertSame(2, $board['openTasks']);
    }

    public function testLaListeSeFiltreEtSeTrie(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $alpha = $this->client('Alpha');
        $this->projet('Zèbre', Projet::EN_COURS, $alpha);
        $this->projet('Abeille', Projet::TERMINE);

        $crawler = $this->browser->request('GET', '/projets/liste?sort=nom&dir=asc');
        self::assertSame('Abeille', trim($crawler->filter('.enc-table tbody tr')->first()->filter('td')->first()->text()));
        $crawler = $this->browser->request('GET', '/projets/liste?statut=ouverts');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/projets/liste?q=alpha');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $this->browser->request('GET', '/projets/liste?statut=zzz&client=abc&sort=zzz&page=abc');
        self::assertResponseIsSuccessful('des paramètres invalides retombent sur les valeurs par défaut');
        $this->browser->request('GET', '/projets/liste?sort=avancement&dir=desc');
        self::assertResponseIsSuccessful();
    }

    public function testLesPermissionsGouvernentChaqueAction(): void
    {
        $projet = $this->projet('Campagne', Projet::EN_COURS);

        $this->login($this->user($this->customRole('Observateur')->getCode(), 'obs@procom.pro'));
        foreach (['/projets', '/projets/liste', '/projets/'.$projet->getId(), '/projets/nouveau'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->login($this->user(User::ROLE_AGENT));
        $this->browser->request('GET', '/projets/nouveau');
        self::assertResponseIsSuccessful();
        $this->browser->request('POST', '/projets/'.$projet->getId().'/supprimer');
        self::assertResponseStatusCodeSame(403, 'l\'agent crée et déplace mais ne supprime pas un projet');
    }

    public function testLaSuppressionEstLogiqueEtCacheLeProjetEtSesTaches(): void
    {
        $projet = $this->projet('Campagne', Projet::EN_COURS);
        $t = $this->task($projet, 'Brief', Tache::A_FAIRE);
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/projets/'.$projet->getId());
        $this->browser->submit($crawler->filter('form[action$="/supprimer"]')->form());
        self::assertResponseRedirects('/projets');

        self::assertTrue($this->fresh(Projet::class, $projet->getId())->isDeleted());
        $this->browser->request('GET', '/projets/'.$projet->getId());
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/projets/taches/'.$t->getId().'/modifier');
        self::assertResponseStatusCodeSame(404, 'les cartes d\'un projet supprimé disparaissent aussi');
        $crawler = $this->browser->request('GET', '/projets');
        self::assertCount(0, $crawler->filter('.kb-card'));
    }

    // ---------- fabriques ----------

    private function client(string $name): Client
    {
        $client = (new Client())->setName($name);
        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    private function projet(string $nom, string $statut, ?Client $client = null, int $position = 0): Projet
    {
        $p = (new Projet())->setNom($nom)->setClient($client)->setPosition($position);
        $p->transition($statut);
        $this->em->persist($p);
        $this->em->flush();

        return $p;
    }

    private function task(Projet $projet, string $titre, string $statut): Tache
    {
        $t = (new Tache())->setProjet($projet)->setTitre($titre);
        $t->transition($statut);
        $this->em->persist($t);
        $projet->getTaches()->add($t);
        $this->em->flush();

        return $t;
    }

    /** Le jeton CSRF des tableaux, lu sur la page comme le fait le navigateur. */
    private function kanbanToken(string $url): string
    {
        return $this->browser->request('GET', $url)->filter('.kb')->attr('data-kanban-token-value');
    }

    private function moveCard(string $url, string $token, string $statut, int $index): void
    {
        $this->browser->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode(['statut' => $statut, 'index' => $index]));
    }
}

/** Une carte minimale pour tester le rangement sans base de données. */
final class Rang
{
    public function __construct(private int $position, public readonly string $name)
    {
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
