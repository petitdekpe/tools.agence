<?php

namespace App\Tests\Controller;

use App\Entity\ActivityLog;
use App\Entity\Personnel;
use App\Entity\User;
use App\Tests\AppWebTestCase;

class PersonnelTest extends AppWebTestCase
{
    public function testCreationDUneFiche(): void
    {
        $this->login($this->user(User::ROLE_DIRECTION));
        $crawler = $this->browser->request('GET', '/personnel/nouveau');
        $this->browser->submit($crawler->selectButton('Créer la fiche')->form([
            'personnel[lastName]' => 'Koffi',
            'personnel[firstName]' => 'Mariam',
            'personnel[jobTitle]' => 'Chargée de projet',
            'personnel[department]' => 'Production',
            'personnel[email]' => 'MARIAM@exemple.bj',
            'personnel[hiredAt]' => '2025-03-01',
            'personnel[contractType]' => 'cdi',
        ]));

        $person = $this->em->getRepository(Personnel::class)->findOneBy(['lastName' => 'Koffi']);
        self::assertNotNull($person);
        self::assertResponseRedirects('/personnel/'.$person->getId());
        self::assertSame('mariam@exemple.bj', $person->getEmail());
        self::assertSame('Mariam KOFFI', $person->getFullName());
        self::assertTrue($person->isActive());
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'personnel.create']));
    }

    public function testValidationDesDatesEtDuContrat(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/personnel/nouveau');
        $this->browser->submit($crawler->selectButton('Créer la fiche')->form([
            'personnel[lastName]' => '',
            'personnel[firstName]' => 'X',
            'personnel[hiredAt]' => '2025-03-01',
            'personnel[leftAt]' => '2024-01-01',
            'personnel[contractType]' => 'cdd',
            'personnel[contractEnd]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le nom est obligatoire.');
        self::assertSelectorTextContains('body', 'La date de départ est antérieure à la date d\'entrée.');
        self::assertSelectorTextContains('body', 'La date de fin est obligatoire pour un CDD.');
        self::assertSame(0, $this->em->getRepository(Personnel::class)->count([]));
    }

    public function testUnCompteNePeutEtreRattacheQuAUneFiche(): void
    {
        $account = $this->user(User::ROLE_AGENT);
        $first = $this->personnel('Premier');
        $first->setUser($account);
        $this->em->flush();
        $other = $this->personnel('Second', 'Bob');

        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/personnel/'.$other->getId().'/modifier');
        $this->browser->submit($crawler->selectButton('Enregistrer')->form(['personnel[user]' => (string) $account->getId()]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Ce compte est déjà rattaché à une autre fiche.');
    }

    public function testLeJournalGardeLAvantEtLApresUneModification(): void
    {
        $person = $this->personnel();
        $this->login($this->user(User::ROLE_DIRECTION));
        $crawler = $this->browser->request('GET', '/personnel/'.$person->getId().'/modifier');
        $this->browser->submit($crawler->selectButton('Enregistrer')->form(['personnel[jobTitle]' => 'Directrice artistique']));
        self::assertResponseRedirects('/personnel/'.$person->getId());

        $log = $this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'personnel.update']);
        self::assertSame(['poste' => 'Graphiste'], $log->getDetails()['avant']);
        self::assertSame(['poste' => 'Directrice artistique'], $log->getDetails()['apres']);
        self::assertSame('Awa DUPONT', $log->getDetails()['personne']);
    }

    public function testAlerteQuandLaPersonnePartAvecDuMateriel(): void
    {
        $person = $this->personnel('Partie', 'Lou', '-3 days');
        $other = $this->personnel('Presente', 'Zoe');
        $laptop = $this->materiel('Portable HP');
        $camera = $this->materiel('Caméra Sony');
        $this->assignDirectly($laptop, $person);
        $this->assignDirectly($camera, $other);

        $this->login($this->user(User::ROLE_ADMIN));
        $this->browser->request('GET', '/personnel');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', '1 non rendu');
        self::assertSelectorTextContains('tbody', '1 en sa possession');

        $this->browser->request('GET', '/personnel/'.$person->getId());
        self::assertSelectorTextContains('.banner.bad', '1 matériel non rendu');
        self::assertSelectorTextContains('body', 'Portable HP');

        $this->browser->request('GET', '/materiel');
        self::assertSelectorTextContains('.banner.warn', '1 matériel est détenu par du personnel parti');
        $crawler = $this->browser->request('GET', '/materiel?affectation=parti');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        self::assertSelectorTextContains('.enc-table tbody', 'Portable HP');
        self::assertSelectorTextContains('.enc-table tbody', 'Détenteur parti');
    }

    public function testFiltresEtTris(): void
    {
        $this->personnel('Alpha', 'A');
        $gone = $this->personnel('Bravo', 'B', '-1 month');
        $gone->setDepartment('Compta');
        $this->em->flush();
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/personnel');
        self::assertCount(2, $crawler->filter('tbody tr'));
        $crawler = $this->browser->request('GET', '/personnel?statut=parti');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'BRAVO');
        $crawler = $this->browser->request('GET', '/personnel?statut=actif');
        self::assertSelectorTextContains('tbody', 'ALPHA');
        $crawler = $this->browser->request('GET', '/personnel?service=Compta');
        self::assertCount(1, $crawler->filter('tbody tr'));
        $crawler = $this->browser->request('GET', '/personnel?q=alp');
        self::assertCount(1, $crawler->filter('tbody tr'));
        $this->browser->request('GET', '/personnel?statut=bidon&contrat=bidon&sort=bidon');
        self::assertResponseIsSuccessful();

        foreach (['nom', 'poste', 'service', 'contrat', 'entree', 'statut'] as $sort) {
            $this->browser->request('GET', "/personnel?sort=$sort&dir=desc");
            self::assertResponseIsSuccessful($sort);
        }
        $crawler = $this->browser->request('GET', '/personnel?sort=nom&dir=desc');
        self::assertStringContainsString('BRAVO', $crawler->filter('tbody tr')->first()->text());
    }

    public function testContratBientotEchuSignale(): void
    {
        $person = $this->personnel();
        $person->setContractType('cdd')->setContractStart(new \DateTimeImmutable('-11 months'))->setContractEnd(new \DateTimeImmutable('+10 days'));
        $this->em->flush();

        $this->login($this->user(User::ROLE_ADMIN));
        $this->browser->request('GET', '/personnel');
        self::assertSelectorTextContains('tbody', 'Fin dans 10 j');
    }

    public function testSuppressionLogiqueReserveAuxAutorises(): void
    {
        $person = $this->personnel();
        $id = $person->getId();

        $this->login($this->user(User::ROLE_DIRECTION)); // peut modifier mais pas supprimer par défaut
        $this->browser->request('POST', "/personnel/$id/supprimer", ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', "/personnel/$id");
        $this->browser->submit($crawler->filter('form[action$="/supprimer"]')->form());
        self::assertResponseRedirects('/personnel');

        $row = $this->em->getConnection()->fetchAssociative('SELECT deleted_at FROM personnel WHERE id = ?', [$id]);
        self::assertNotNull($row['deleted_at'], 'la ligne reste en base');
        $this->browser->request('GET', "/personnel/$id");
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/personnel');
        self::assertSelectorTextContains('tbody', 'Aucune fiche');
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'personnel.delete']));
    }

    public function testImpossibleDeSupprimerUnePersonneQuiDetientDuMateriel(): void
    {
        $person = $this->personnel();
        $this->assignDirectly($this->materiel(), $person);

        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/personnel/'.$person->getId());
        $this->browser->submit($crawler->filter('form[action$="/supprimer"]')->form());
        $this->browser->followRedirect();

        self::assertSelectorTextContains('.alert.error', 'détient encore du matériel');
        self::assertFalse($this->fresh(Personnel::class, $person->getId())->isDeleted());
    }

    public function testSansPermissionMaterielLaFicheNeMontrePasLInventaire(): void
    {
        $person = $this->personnel();
        $this->assignDirectly($this->materiel('Secret Laptop'), $person);

        $manager = static::getContainer()->get(\App\Service\PermissionManager::class);
        $manager->save($this->role(User::ROLE_DIRECTION), [\App\Security\Permission::PERSONNEL_VIEW]);

        $this->login($this->user(User::ROLE_DIRECTION));
        $this->browser->request('GET', '/personnel/'.$person->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Secret Laptop');
        self::assertSelectorTextNotContains('body', 'Matériel en sa possession');
        $this->browser->request('GET', '/personnel');
        self::assertSelectorTextNotContains('thead', 'Matériel');
    }
}
