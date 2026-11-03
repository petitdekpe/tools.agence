<?php

namespace App\Tests\Controller;

use App\Entity\ActivityLog;
use App\Entity\Affectation;
use App\Entity\Materiel;
use App\Entity\User;
use App\Tests\AppWebTestCase;

class MaterielTest extends AppWebTestCase
{
    public function testCreationEtReferenceUnique(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $this->submitNew('MacBook Pro 14', 'SN-001', '1500000');
        $item = $this->em->getRepository(Materiel::class)->findOneBy(['reference' => 'SN-001']);
        self::assertNotNull($item);
        self::assertResponseRedirects('/materiel/'.$item->getId());
        self::assertSame('1500000.00', $item->getPurchaseValue());
        self::assertSame('bon', $item->getEtat());
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'materiel.create']));

        $this->submitNew('Autre ordinateur', 'SN-001');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Cette référence est déjà utilisée');
        self::assertSame(1, $this->em->getRepository(Materiel::class)->count([]));
    }

    public function testValidationDesChampsObligatoires(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $this->submitNew('', null, '-5');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La désignation est obligatoire.');
        self::assertSelectorTextContains('body', 'La valeur ne peut pas être négative.');
        self::assertSame(0, $this->em->getRepository(Materiel::class)->count([]));
    }

    public function testRemiseUnSeulDetenteurALaFoisPuisRestitution(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $awa = $this->personnel('Dupont', 'Awa');
        $bob = $this->personnel('Martin', 'Bob');
        $item = $this->materiel();
        $this->login($agent);

        $this->assign($item->getId(), $awa->getId());
        self::assertResponseRedirects('/materiel/'.$item->getId());
        $item = $this->fresh(Materiel::class, $item->getId());
        self::assertSame($awa->getId(), $item->getHolder()->getId());
        self::assertSame($agent->getId(), $item->getCurrentAffectation()->getAssignedBy()->getId());
        self::assertFalse($item->isAvailable());

        // Déjà affecté : le formulaire de remise n'existe plus, et un POST direct est refusé par la règle métier.
        $crawler = $this->browser->request('GET', '/materiel/'.$item->getId());
        self::assertCount(0, $crawler->filter('form[action$="/affecter"]'));
        $this->browser->request('POST', '/materiel/'.$item->getId().'/affecter', ['assign' => ['personnel' => (string) $bob->getId(), 'assignedAt' => date('Y-m-d'), '_token' => 'x']]);
        self::assertSame(1, $this->em->getRepository(Affectation::class)->count(['materiel' => $item->getId(), 'returnedAt' => null]));
        self::assertSame($awa->getId(), $this->fresh(Materiel::class, $item->getId())->getHolder()->getId());

        // Restitution avec changement d'état.
        $crawler = $this->browser->request('GET', '/materiel/'.$item->getId());
        $this->browser->submit($crawler->selectButton('Enregistrer la restitution')->form(['give_back[etat]' => 'panne', 'give_back[note]' => 'Écran fissuré']));
        self::assertResponseRedirects('/materiel/'.$item->getId());

        $item = $this->fresh(Materiel::class, $item->getId());
        self::assertNull($item->getHolder());
        self::assertSame('panne', $item->getEtat());
        $affectation = $item->getAffectations()->first();
        self::assertSame(date('Y-m-d'), $affectation->getReturnedAt()->format('Y-m-d'));
        self::assertSame('Écran fissuré', $affectation->getReturnNote());
        self::assertSame('panne', $affectation->getReturnEtat());

        // Remis ensuite à Bob (un matériel en panne reste remettable), l'historique garde les deux passages.
        $this->assign($item->getId(), $bob->getId());
        $item = $this->fresh(Materiel::class, $item->getId());
        self::assertCount(2, $item->getAffectations());
        self::assertSame($bob->getId(), $item->getHolder()->getId());

        foreach (['materiel.assign', 'materiel.return'] as $action) {
            self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => $action]), $action);
        }
        $this->browser->request('GET', '/materiel/'.$item->getId());
        self::assertSelectorTextContains('.enc-table', 'Awa DUPONT');
        self::assertSelectorTextContains('.enc-table', 'Écran fissuré');
    }

    public function testRemiseRefuseePourUnePersonnePartieOuUnMaterielReforme(): void
    {
        $gone = $this->personnel('Partie', 'Lou', '-2 days');
        $current = $this->personnel('Presente', 'Zoe');
        $item = $this->materiel();
        $reformed = $this->materiel('Vieux PC', null, 'reforme');
        $this->login($this->user(User::ROLE_AGENT));

        // La personne partie n'est pas proposée dans la liste.
        $crawler = $this->browser->request('GET', '/materiel/'.$item->getId());
        $options = $crawler->filter('select[name="assign[personnel]"] option')->each(fn ($o) => $o->text());
        self::assertContains('Zoe PRESENTE · Graphiste', $options);
        self::assertNotContains('Lou PARTIE · Graphiste', $options);

        // Un POST forgé est tout de même refusé : la règle vit dans le service, pas dans la liste déroulante.
        $assigner = static::getContainer()->get(\App\Service\MaterielAssigner::class);
        $this->em->clear();
        $find = fn (string $class, int $id) => $this->em->find($class, $id);
        try {
            $assigner->assign($find(Materiel::class, $item->getId()), $find(\App\Entity\Personnel::class, $gone->getId()), new \DateTimeImmutable('today'), null, null);
            self::fail('Remise à une personne partie acceptée.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('n\'est plus en poste', $e->getMessage());
        }
        try {
            $assigner->assign($find(Materiel::class, $reformed->getId()), $find(\App\Entity\Personnel::class, $current->getId()), new \DateTimeImmutable('today'), null, null);
            self::fail('Remise d\'un matériel réformé acceptée.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('réformé', $e->getMessage());
        }

        $this->browser->request('GET', '/materiel/'.$reformed->getId());
        self::assertCount(0, $this->browser->getCrawler()->filter('form[action$="/affecter"]'));
        self::assertSelectorTextContains('body', 'Matériel réformé');
    }

    public function testDatesDeRemiseEtDeRestitutionCoherentes(): void
    {
        $person = $this->personnel();
        $item = $this->materiel();
        $this->assignDirectly($item, $person, '-5 days');
        $this->login($this->user(User::ROLE_AGENT));

        $crawler = $this->browser->request('GET', '/materiel/'.$item->getId());
        $this->browser->submit($crawler->selectButton('Enregistrer la restitution')->form(['give_back[returnedAt]' => date('Y-m-d', strtotime('-10 days'))]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La restitution est antérieure à la remise');
        self::assertNotNull($this->fresh(Materiel::class, $item->getId())->getHolder(), 'rien n\'a été clôturé');
    }

    public function testImpossibleDeReformerUnMaterielAffecte(): void
    {
        $item = $this->materiel();
        $this->assignDirectly($item, $this->personnel());
        $this->login($this->user(User::ROLE_AGENT));

        $crawler = $this->browser->request('GET', '/materiel/'.$item->getId().'/modifier');
        $this->browser->submit($crawler->selectButton('Enregistrer')->form(['materiel[etat]' => 'reforme']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'restituez-le avant de le réformer');
        self::assertSame('bon', $this->fresh(Materiel::class, $item->getId())->getEtat());
    }

    public function testModificationJournalisee(): void
    {
        $item = $this->materiel('Vidéoprojecteur', 'VP-1');
        $this->login($this->user(User::ROLE_AGENT));
        $crawler = $this->browser->request('GET', '/materiel/'.$item->getId().'/modifier');
        $this->browser->submit($crawler->selectButton('Enregistrer')->form(['materiel[etat]' => 'use']));
        self::assertResponseRedirects('/materiel/'.$item->getId());

        $log = $this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'materiel.update']);
        self::assertSame(['etat' => 'Bon état'], $log->getDetails()['avant']);
        self::assertSame(['etat' => 'Usé'], $log->getDetails()['apres']);
    }

    public function testSuppressionLogiqueEtReferenceReutilisable(): void
    {
        $item = $this->materiel('Imprimante', 'IMP-1');
        $id = $item->getId();

        $this->login($this->user(User::ROLE_AGENT)); // pas la permission de supprimer par défaut
        $this->browser->request('POST', "/materiel/$id/supprimer", ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', "/materiel/$id");
        $this->browser->submit($crawler->filter('form[action$="/supprimer"]')->form());
        self::assertResponseRedirects('/materiel');
        self::assertNotNull($this->em->getConnection()->fetchOne('SELECT deleted_at FROM materiel WHERE id = ?', [$id]), 'la ligne reste en base');
        $this->browser->request('GET', "/materiel/$id");
        self::assertResponseStatusCodeSame(404);

        $this->submitNew('Imprimante neuve', 'IMP-1'); // la référence d'un matériel supprimé redevient libre
        self::assertResponseRedirects();
    }

    public function testImpossibleDeSupprimerUnMaterielAffecte(): void
    {
        $item = $this->materiel();
        $this->assignDirectly($item, $this->personnel());
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/materiel/'.$item->getId());
        self::assertCount(0, $crawler->filter('form[action$="/supprimer"]'), 'bouton masqué tant que le matériel est affecté');
        $this->browser->request('POST', '/materiel/'.$item->getId().'/supprimer', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403); // jeton invalide
    }

    public function testFiltresTrisTotauxEtExports(): void
    {
        $awa = $this->personnel('Dupont', 'Awa');
        $laptop = $this->materiel('Portable', 'A-1');
        $laptop->setCategory('informatique')->setPurchaseValue('800000');
        $phone = $this->materiel('Téléphone', 'B-2', 'panne');
        $phone->setCategory('telephonie')->setPurchaseValue('200000');
        $chair = $this->materiel('=CMD("x")', null);
        $chair->setCategory('mobilier');
        $this->em->flush();
        $this->assignDirectly($laptop, $awa);
        $this->login($this->user(User::ROLE_AGENT));

        $crawler = $this->browser->request('GET', '/materiel');
        self::assertCount(3, $crawler->filter('.enc-table tbody tr'));
        self::assertStringContainsString("1\u{00A0}000\u{00A0}000", $crawler->filter('.enc-table tfoot')->text());
        self::assertStringContainsString('1 affecté', $crawler->filter('.enc-table tfoot')->text());

        $crawler = $this->browser->request('GET', '/materiel?affectation=affecte');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        self::assertStringContainsString("800\u{00A0}000", $crawler->filter('.enc-table tfoot')->text());
        $crawler = $this->browser->request('GET', '/materiel?affectation=disponible');
        self::assertCount(2, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/materiel?etat=panne');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/materiel?categorie=mobilier');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/materiel?detenteur='.$awa->getId());
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/materiel?q=B-2');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $this->browser->request('GET', '/materiel?etat=bidon&categorie=bidon&affectation=bidon&sort=bidon');
        self::assertResponseIsSuccessful();
        foreach (['designation', 'categorie', 'reference', 'etat', 'detenteur', 'achat', 'valeur'] as $sort) {
            $this->browser->request('GET', "/materiel?sort=$sort&dir=desc");
            self::assertResponseIsSuccessful($sort);
        }

        $this->browser->request('GET', '/materiel/export.xlsx?categorie=informatique');
        self::assertResponseIsSuccessful();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $this->browser->getResponse()->getContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);
        self::assertSame('Portable', $sheet->getCell('A6')->getValue());
        self::assertSame('Awa DUPONT', $sheet->getCell('E6')->getValue());
        self::assertEquals(800000, $sheet->getCell('H6')->getValue());
        self::assertEquals(800000, $sheet->getCell('H7')->getValue());
        self::assertStringContainsString('catégorie Informatique', $sheet->getCell('A2')->getValue());

        $this->browser->request('GET', '/materiel/export.xlsx');
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $this->browser->getResponse()->getContent());
        $all = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);
        $names = [$all->getCell('A6')->getValue(), $all->getCell('A7')->getValue(), $all->getCell('A8')->getValue()];
        self::assertContains('=CMD("x")', $names);
        foreach (['A6', 'A7', 'A8'] as $cell) {
            self::assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $all->getCell($cell)->getDataType(), 'jamais de formule');
        }

        $this->browser->request('GET', '/materiel/export.pdf');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF', $this->browser->getResponse()->getContent());
    }

    public function testSansPermissionExportLesBoutonsDisparaissent(): void
    {
        $manager = static::getContainer()->get(\App\Service\PermissionManager::class);
        $manager->save($this->role(User::ROLE_AGENT), [\App\Security\Permission::MATERIEL_VIEW]);

        $this->materiel();
        $this->login($this->user(User::ROLE_AGENT));
        $this->browser->request('GET', '/materiel');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Export Excel');
        self::assertSelectorNotExists('a[href*="/materiel/nouveau"]');
        $this->browser->request('GET', '/materiel/nouveau');
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->browser->request('GET', '/materiel/'.$this->em->getRepository(Materiel::class)->findOneBy([])->getId());
        self::assertCount(0, $crawler->filter('form'), 'ni remise ni restitution sans la permission');
    }

    private function submitNew(string $designation, ?string $reference = null, string $value = ''): void
    {
        $crawler = $this->browser->request('GET', '/materiel/nouveau');
        $this->browser->submit($crawler->selectButton('Ajouter à l\'inventaire')->form([
            'materiel[designation]' => $designation,
            'materiel[reference]' => $reference ?? '',
            'materiel[purchaseValue]' => $value,
        ]));
    }

    private function assign(int $materielId, int $personnelId): void
    {
        $crawler = $this->browser->request('GET', "/materiel/$materielId");
        $this->browser->submit($crawler->selectButton('Enregistrer la remise')->form(['assign[personnel]' => (string) $personnelId]));
    }
}
