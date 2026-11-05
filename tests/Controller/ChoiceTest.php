<?php

namespace App\Tests\Controller;

use App\Entity\ActivityLog;
use App\Entity\Choice;
use App\Entity\Materiel;
use App\Entity\Ticket;
use App\Entity\User;
use App\Service\Choices;
use App\Tests\AppWebTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Listes de choix modifiables : ajout, renommage, ordre, suppression protégée, et effet dans les formulaires, filtres et tris. */
class ChoiceTest extends AppWebTestCase
{
    public function testSeulUnAdminGereLesListes(): void
    {
        $this->login($this->user(User::ROLE_DIRECTION));
        $this->browser->request('GET', '/admin/listes');
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('GET', '/admin/listes/materiel_category');
        self::assertResponseStatusCodeSame(403);

        $this->login($this->user(User::ROLE_ADMIN));
        $this->browser->request('GET', '/admin/listes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Catégories de matériel');
        self::assertSelectorTextContains('body', 'Canaux de publication');

        $this->browser->request('GET', '/admin/listes/inconnue');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUneListeNonModifieeAffichePartoutSesValeursDOrigine(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/admin/listes/materiel_category');
        self::assertResponseIsSuccessful();
        self::assertCount(\count(Materiel::CATEGORIES), $crawler->filter('input[name="label"][aria-label^="Libellé"]'));
        self::assertSelectorTextContains('body', 'Code : informatique');
        self::assertSame(Materiel::CATEGORIES, static::getContainer()->get(Choices::class)->labels('materiel_category'));
    }

    public function testAjouterUneValeurLaRendDisponibleEtValide(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/admin/listes/materiel_category');
        $this->browser->submit($crawler->selectButton('+ Ajouter')->form(['label' => 'Papeterie et bureau']));
        self::assertResponseRedirects('/admin/listes/materiel_category');

        $labels = static::getContainer()->get(Choices::class)->labels('materiel_category');
        self::assertSame('Papeterie et bureau', $labels['papeterie_et_bureau'] ?? null);
        self::assertSame('Informatique', $labels['informatique'], 'les valeurs d\'origine sont conservées');
        self::assertSame(array_key_last($labels), 'papeterie_et_bureau', 'ajoutée en fin de liste');
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'choice.create']));

        // Elle est proposée dans le formulaire du matériel et acceptée à l'enregistrement.
        $crawler = $this->browser->request('GET', '/materiel/nouveau');
        self::assertCount(1, $crawler->filter('select[name="materiel[category]"] option[value="papeterie_et_bureau"]'));
        $this->browser->submit($crawler->selectButton('Ajouter à l\'inventaire')->form(['materiel[designation]' => 'Agrafeuse', 'materiel[category]' => 'papeterie_et_bureau']));
        self::assertResponseRedirects();
        $item = $this->em->getRepository(Materiel::class)->findOneBy(['designation' => 'Agrafeuse']);
        self::assertSame('Papeterie et bureau', $item->getCategoryLabel());

        // Et le filtre de l'inventaire la reconnaît.
        $crawler = $this->browser->request('GET', '/materiel?categorie=papeterie_et_bureau');
        self::assertSelectorTextContains('body', 'Agrafeuse');
    }

    public function testUnLibelleVideTropCourtOuDejaPrisEstRefuse(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $choices = static::getContainer()->get(Choices::class);
        $before = $choices->labels('depense_categorie');

        foreach (['', 'x', 'loyer et charges', str_repeat('a', 81)] as $label) {
            $crawler = $this->browser->request('GET', '/admin/listes/depense_categorie');
            $this->browser->submit($crawler->selectButton('+ Ajouter')->form(['label' => $label]));
            self::assertResponseRedirects();
            $this->browser->followRedirect();
            self::assertSelectorExists('.alert.error', "refusé : « $label »");
        }

        self::assertSame($before, static::getContainer()->get(Choices::class)->labels('depense_categorie'));
    }

    public function testRenommerChangeLAffichagePartoutSansToucherAuxDonnees(): void
    {
        $item = $this->materiel('MacBook Pro 14');
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/admin/listes/materiel_category');
        $form = $crawler->filter('form[action$="/renommer"]')->reduce(static fn ($n) => 'Informatique' === $n->filter('input[name="label"]')->attr('value'))->form(['label' => 'Informatique et réseau']);
        $this->browser->submit($form);
        self::assertResponseRedirects();

        $fresh = $this->fresh(Materiel::class, $item->getId());
        self::assertSame('informatique', $fresh->getCategory(), 'le code stocké ne change pas');
        self::assertSame('Informatique et réseau', $fresh->getCategoryLabel());
        $this->browser->request('GET', '/materiel/'.$item->getId());
        self::assertSelectorTextContains('body', 'Informatique et réseau');
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'choice.update']));
    }

    public function testMonterEtDescendreChangeLOrdre(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $choices = static::getContainer()->get(Choices::class);
        $first = $choices->manage('absence_type')[0];

        $crawler = $this->browser->request('GET', '/admin/listes/absence_type');
        $this->browser->submit($crawler->filter('form[action$="/'.$first->getId().'/deplacer"]')->reduce(static fn ($n) => 'down' === $n->filter('input[name="sens"]')->attr('value'))->form());

        $order = array_keys(static::getContainer()->get(Choices::class)->labels('absence_type'));
        self::assertSame('maladie', $order[0]);
        self::assertSame('conge', $order[1]);

        $crawler = $this->browser->request('GET', '/admin/listes/absence_type');
        $this->browser->submit($crawler->filter('form[action$="/'.$first->getId().'/deplacer"]')->reduce(static fn ($n) => 'up' === $n->filter('input[name="sens"]')->attr('value'))->form());
        self::assertSame('conge', array_key_first(static::getContainer()->get(Choices::class)->labels('absence_type')));
    }

    public function testUneValeurInutiliseSeSupprimeUneValeurUtiliseeOuVerrouilleeNon(): void
    {
        $this->materiel('MacBook Pro 14'); // catégorie « informatique », verrouillée et utilisée
        $this->login($this->user(User::ROLE_ADMIN));
        $choices = static::getContainer()->get(Choices::class);
        $rows = array_column(array_map(static fn (Choice $c): array => [$c->getCode(), $c], $choices->manage('materiel_category')), 1, 0);

        // Verrouillée : pas de bouton, et la requête directe est refusée.
        $crawler = $this->browser->request('GET', '/admin/listes/materiel_category');
        self::assertCount(0, $crawler->filter('form[action$="/'.$rows['informatique']->getId().'/supprimer"]'));
        self::assertSelectorTextContains('body', 'Verrouillée');
        $this->postWithToken('/admin/listes/materiel_category', '/admin/listes/materiel_category/'.$rows['informatique']->getId().'/supprimer');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.error', 'ne peut pas être supprimé');
        self::assertArrayHasKey('informatique', static::getContainer()->get(Choices::class)->labels('materiel_category'));

        // Utilisée (non verrouillée) : refusée avec le détail.
        $this->materiel('Perceuse')->setCategory('outillage');
        $this->em->flush();
        $this->postWithToken('/admin/listes/materiel_category', '/admin/listes/materiel_category/'.$rows['outillage']->getId().'/supprimer');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.error', 'encore utilisé (1 matériel)');
        self::assertArrayHasKey('outillage', static::getContainer()->get(Choices::class)->labels('materiel_category'));

        // Inutilisée : supprimée.
        $this->postWithToken('/admin/listes/materiel_category', '/admin/listes/materiel_category/'.$rows['mobilier']->getId().'/supprimer');
        self::assertResponseRedirects('/admin/listes/materiel_category');
        self::assertArrayNotHasKey('mobilier', static::getContainer()->get(Choices::class)->labels('materiel_category'));
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'choice.delete']));
    }

    public function testUneListeGardeAuMoinsUneValeur(): void
    {
        $choices = static::getContainer()->get(Choices::class);
        $rows = $choices->manage('depense_mode'); // aucune valeur verrouillée
        foreach (\array_slice($rows, 1) as $row) {
            $choices->delete($row);
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('au moins une valeur');
        $choices->delete($rows[0]);
    }

    public function testUneValeurSupprimeeEstRefuseeParLaValidation(): void
    {
        $choices = static::getContainer()->get(Choices::class);
        $mobilier = array_values(array_filter($choices->manage('materiel_category'), static fn (Choice $c): bool => 'mobilier' === $c->getCode()))[0];
        $choices->delete($mobilier);

        $validator = static::getContainer()->get(ValidatorInterface::class);
        $item = (new Materiel())->setDesignation('Bureau')->setCategory('mobilier');
        self::assertGreaterThan(0, \count($validator->validateProperty($item, 'category')));
        self::assertCount(0, $validator->validateProperty($item->setCategory('vehicule'), 'category'));
    }

    public function testLOrdreDesPrioritesPiloteLeTriDesTickets(): void
    {
        $choices = static::getContainer()->get(Choices::class);
        $choices->add('ticket_priorite', 'Critique'); // plus urgente que « Urgente »
        $normal = (new Ticket(Ticket::TYPE_PANNE))->setReference('PN-2026-001')->setTitre('Normal')->setPriorite('normale');
        $urgent = (new Ticket(Ticket::TYPE_PANNE))->setReference('PN-2026-002')->setTitre('Urgent')->setPriorite('urgente');
        $critique = (new Ticket(Ticket::TYPE_PANNE))->setReference('PN-2026-003')->setTitre('Critique')->setPriorite('critique');
        foreach ([$normal, $urgent, $critique] as $t) {
            $this->em->persist($t);
        }
        $this->em->flush();

        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/pannes?type=panne&sort=priorite&dir=desc');
        $refs = $crawler->filter('.enc-table tbody tr')->each(static fn ($tr) => trim($tr->filter('td')->first()->text()));
        self::assertSame(['PN-2026-003', 'PN-2026-002', 'PN-2026-001'], $refs);

        $crawler = $this->browser->request('GET', '/pannes?type=panne&priorite=critique');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
    }

    public function testTouteListeADesValeursEtAUneDefinitionComplete(): void
    {
        foreach (Choices::definitions() as $key => $definition) {
            self::assertNotEmpty($definition['defaults'], $key);
            foreach ($definition['locked'] as $code) {
                self::assertArrayHasKey($code, $definition['defaults'], "$key : $code verrouillé mais absent des valeurs d'origine");
            }
            foreach ($definition['uses'] as [$class, $field]) {
                self::assertTrue(property_exists($class, $field), "$key : $class::\$$field n'existe pas");
            }
        }
    }

    /** Poste vers $action avec le jeton lu sur la page de la liste, comme le fait le navigateur. */
    private function postWithToken(string $page, string $action): void
    {
        $token = $this->browser->request('GET', $page)->filter('input[name="_token"]')->first()->attr('value');
        $this->browser->request('POST', $action, ['_token' => $token]);
    }
}
