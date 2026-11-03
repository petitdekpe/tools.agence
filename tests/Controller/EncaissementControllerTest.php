<?php

namespace App\Tests\Controller;

use App\Entity\ActivityLog;
use App\Entity\Client;
use App\Entity\Encaissement;
use App\Entity\FiscalSetting;
use App\Entity\Role;
use App\Entity\User;
use App\Service\RoleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EncaissementControllerTest extends WebTestCase
{
    private KernelBrowser $browser;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        foreach (['encaissement', 'role_permission', 'activity_log', 'client', 'fiscal_setting', 'app_user', 'app_role'] as $table) {
            $this->em->getConnection()->executeStatement('DELETE FROM '.$table);
        }
        static::getContainer()->get(RoleManager::class)->ensureSystemRoles();
        $this->em->getConnection()->executeStatement('ALTER TABLE encaissement AUTO_INCREMENT = 1');
    }

    public function testToutUtilisateurSaisitUnEncaissementEtLesMontantsSontCalcules(): void
    {
        $client = $this->client('Société A', '3201234567890');
        $this->login($this->user('agent@procom.pro', User::ROLE_AGENT));

        $this->submitNew($client, ['ttc' => '118000', 'especes' => '17000', 'cheque' => '100000']);
        self::assertResponseRedirects('/encaissements');

        $e = $this->encaissement();
        self::assertSame('100000.00', $e->getMontantHt());
        self::assertSame('18000.00', $e->getTvaAReverser());
        self::assertSame('0.00', $e->getTvaPreleveeSource());
        self::assertSame('1000.00', $e->getAibPreleveSource());
        self::assertSame('117000.00', $e->getMontantNetRecu());
        self::assertSame('18.00', $e->getTauxTva());
        self::assertSame('1.00', $e->getTauxAib());
        self::assertSame('agent@procom.pro', $e->getCreatedBy()->getEmail());
        self::assertTrue($e->isCoherent());
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'encaissement.create']));
    }

    public function testLesChampsCalculesEnvoyesParLeNavigateurSontRefuses(): void
    {
        $client = $this->client('Société A', '3201234567890');
        $this->login($this->user('agent@procom.pro', User::ROLE_AGENT));

        $crawler = $this->browser->request('GET', '/encaissements/nouveau');
        $form = $crawler->selectButton('Enregistrer l\'encaissement')->form();
        $values = $form->getPhpValues();
        $values['encaissement'] = array_merge($values['encaissement'], [
            'dateEncaissement' => date('Y-m-d'),
            'client' => (string) $client->getId(),
            'montantTtc' => '118000',
            'montantNetRecu' => '1',
            'montantHt' => '1',
        ]);
        $this->browser->request('POST', '/encaissements/nouveau', $values);

        self::assertResponseStatusCodeSame(422); // formulaire réaffiché avec une erreur, rien d'enregistré
        self::assertSame(0, $this->em->getRepository(Encaissement::class)->count([]));
    }

    public function testUneRepartitionQuiDepasseLeNetEstRefusee(): void
    {
        $client = $this->client('Société A', '3201234567890');
        $this->login($this->user('agent@procom.pro', User::ROLE_AGENT));

        $this->submitNew($client, ['ttc' => '118000', 'especes' => '100000', 'cheque' => '20000']); // 120 000 > 117 000
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La répartition dépasse le net reçu de 3 000 FCFA');
        self::assertSame(0, $this->em->getRepository(Encaissement::class)->count([]));
    }

    public function testUneRepartitionIncompleteEstAccepteeEtSignaleeDansLaListe(): void
    {
        $client = $this->client('Société A', '3201234567890');
        $this->login($this->user('agent@procom.pro', User::ROLE_AGENT));

        $this->submitNew($client, ['ttc' => '118000', 'especes' => '100000', 'cheque' => '0']);
        self::assertResponseRedirects('/encaissements');
        $this->browser->followRedirect();

        self::assertSelectorTextContains('.enc-table tbody', "Écart de 17\u{00A0}000 FCFA");
        self::assertSelectorTextContains('.enc-table tfoot', 'Total');
    }

    public function testValidationDesChampsObligatoires(): void
    {
        $this->client('Société A', '3201234567890');
        $this->login($this->user('agent@procom.pro', User::ROLE_AGENT));

        $crawler = $this->browser->request('GET', '/encaissements/nouveau');
        $form = $crawler->selectButton('Enregistrer l\'encaissement')->form(['encaissement[montantTtc]' => '0', 'encaissement[dateEncaissement]' => '']);
        $this->browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le montant TTC doit être supérieur à 0.');
        self::assertSelectorTextContains('body', 'La date est obligatoire.');
        self::assertSelectorTextContains('body', 'Le client est obligatoire.');
    }

    public function testLeTauxFigeSurvitAUnChangementDeParametres(): void
    {
        $client = $this->client('Société A', '3201234567890');
        $this->login($this->user('agent@procom.pro', User::ROLE_AGENT));
        $this->submitNew($client, ['ttc' => '118000', 'especes' => '117000', 'cheque' => '0']);

        $setting = $this->em->getRepository(FiscalSetting::class)->findOneBy([]);
        $setting->setTauxTva('20.00')->setTauxAibAvecIfu('3.00');
        $this->em->flush();

        $crawler = $this->browser->request('GET', '/encaissements/'.$this->encaissement()->getId().'/modifier');
        $this->browser->submit($crawler->selectButton('Enregistrer les modifications')->form(['encaissement[observation]' => 'Payé en deux fois']));
        self::assertResponseRedirects('/encaissements');

        $e = $this->encaissement();
        self::assertSame('Payé en deux fois', $e->getObservation());
        self::assertSame('18.00', $e->getTauxTva());
        self::assertSame('117000.00', $e->getMontantNetRecu());

        // Un nouvel encaissement prend les nouveaux taux.
        $this->submitNew($client, ['ttc' => '120000', 'especes' => '0', 'cheque' => '0']);
        $latest = $this->em->getRepository(Encaissement::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame('20.00', $latest->getTauxTva());
        self::assertSame('3.00', $latest->getTauxAib());
    }

    public function testLeJournalGardeLAvantEtLApresUneModification(): void
    {
        $client = $this->client('Société A', '3201234567890');
        $this->login($this->user('agent@procom.pro', User::ROLE_AGENT));
        $this->submitNew($client, ['ttc' => '118000', 'especes' => '117000', 'cheque' => '0']);

        $crawler = $this->browser->request('GET', '/encaissements/'.$this->encaissement()->getId().'/modifier');
        $this->browser->submit($crawler->selectButton('Enregistrer les modifications')->form(['encaissement[montantTtc]' => '236000', 'encaissement[montantEspecesMomo]' => '234000']));
        self::assertResponseRedirects('/encaissements');

        $log = $this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'encaissement.update']);
        self::assertNotNull($log);
        self::assertSame(118000, $log->getDetails()['avant']['ttc']);
        self::assertSame(236000, $log->getDetails()['apres']['ttc']);
        self::assertSame(117000, $log->getDetails()['avant']['net_recu']);
        self::assertSame(234000, $log->getDetails()['apres']['net_recu']);
        self::assertSame('agent@procom.pro', $log->getUserEmail());
    }

    public function testSeulLAdminSupprimeEtLaSuppressionEstLogique(): void
    {
        $client = $this->client('Société A', '3201234567890');
        $agent = $this->user('agent@procom.pro', User::ROLE_AGENT);
        $this->login($agent);
        $this->submitNew($client, ['ttc' => '118000', 'especes' => '117000', 'cheque' => '0']);
        $id = $this->encaissement()->getId();

        // Un agent n'a pas le droit, même avec un jeton valide.
        $this->browser->request('POST', "/encaissements/$id/supprimer", ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertFalse($this->encaissement()->isDeleted());

        // L'admin voit le bouton et supprime.
        $this->login($this->user('admin@procom.pro', User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/encaissements');
        $this->browser->submit($crawler->filter('form[action$="/supprimer"]')->form());
        self::assertResponseRedirects('/encaissements');

        $this->em->clear();
        $row = $this->em->getConnection()->fetchAssociative('SELECT deleted_at, deleted_by_id FROM encaissement WHERE id = ?', [$id]);
        self::assertNotFalse($row, 'la ligne reste en base');
        self::assertNotNull($row['deleted_at']);
        self::assertNotNull($row['deleted_by_id']);

        $this->browser->request('GET', '/encaissements');
        self::assertSelectorNotExists('.enc-table tbody form');
        self::assertSelectorTextContains('.enc-table tbody', 'Aucun encaissement');
        $this->browser->request('GET', "/encaissements/$id/modifier");
        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'encaissement.delete']));
    }

    public function testFiltresTrisEtTotauxSuiventLesFiltres(): void
    {
        $a = $this->client('Alpha', '111');
        $b = $this->client('Bravo');
        $this->login($this->user('agent@procom.pro', User::ROLE_AGENT));
        $this->submitNew($a, ['ttc' => '118000', 'especes' => '117000', 'cheque' => '0']);
        $this->submitNew($b, ['ttc' => '236000', 'especes' => '0', 'cheque' => '100000']); // sans IFU : AIB 5 %, écart

        $crawler = $this->browser->request('GET', '/encaissements');
        self::assertCount(2, $crawler->filter('.enc-table tbody tr'));
        self::assertStringContainsString("354\u{00A0}000", $crawler->filter('.enc-table tfoot tr')->text());

        $crawler = $this->browser->request('GET', '/encaissements?client='.$b->getId());
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        self::assertStringContainsString("236\u{00A0}000", $crawler->filter('.enc-table tfoot')->text());
        self::assertStringNotContainsString("354\u{00A0}000", $crawler->filter('.enc-table tfoot')->text());

        $crawler = $this->browser->request('GET', '/encaissements?coherence=ecart');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/encaissements?coherence=ok');
        self::assertStringContainsString('Alpha', $crawler->filter('.enc-table tbody')->text());
        $crawler = $this->browser->request('GET', '/encaissements?mode=virement');
        self::assertStringContainsString('Bravo', $crawler->filter('.enc-table tbody')->text());
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/encaissements?q=alp');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));

        foreach (['date', 'client', 'ttc', 'ht', 'tva_source', 'tva_reverser', 'aib', 'net', 'especes', 'cheque', 'observation', 'coherence'] as $sort) {
            $this->browser->request('GET', "/encaissements?sort=$sort&dir=asc");
            self::assertResponseIsSuccessful("tri sur $sort");
        }
        $crawler = $this->browser->request('GET', '/encaissements?sort=client&dir=desc');
        self::assertStringStartsWith('Bravo', trim($crawler->filter('.enc-table tbody tr')->first()->filter('td')->eq(1)->text()));
    }

    public function testNatureAvanceEstEnregistreeFiltreeEtCalculeeCommeUneFacture(): void
    {
        $client = $this->client('Alpha', '111');
        $this->login($this->user('agent@procom.pro', User::ROLE_AGENT));

        $this->submitNew($client, ['ttc' => '118000', 'especes' => '117000', 'cheque' => '0', 'nature' => 'avance']);
        self::assertResponseRedirects('/encaissements');
        $this->submitNew($client, ['ttc' => '59000', 'especes' => '58500', 'cheque' => '0']); // nature par défaut

        $avance = $this->em->getRepository(Encaissement::class)->findOneBy(['nature' => 'avance']);
        self::assertSame('117000.00', $avance->getMontantNetRecu());
        self::assertSame('18000.00', $avance->getTvaAReverser());
        self::assertSame('Avance / acompte', $avance->getNatureLabel());
        self::assertSame(1, $this->em->getRepository(Encaissement::class)->count(['nature' => 'facture']));

        $crawler = $this->browser->request('GET', '/encaissements?nature=avance');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        self::assertSelectorTextContains('.enc-table tbody', 'Avance / acompte');
        self::assertStringContainsString("118\u{00A0}000", $crawler->filter('.enc-table tfoot')->text());

        $this->browser->request('GET', '/encaissements?nature=inconnue');
        self::assertResponseIsSuccessful(); // valeur ignorée
        $this->browser->request('GET', '/encaissements?sort=nature');
        self::assertResponseIsSuccessful();

        $this->browser->request('GET', '/encaissements/synthese');
        self::assertSelectorTextContains('.enc-natures', 'Avance / acompte');
        self::assertSelectorTextContains('.enc-natures', 'Paiement de facture');
    }

    public function testUneNatureInconnueEstRefusee(): void
    {
        $client = $this->client('Alpha', '111');
        $this->login($this->user('agent@procom.pro', User::ROLE_AGENT));

        $crawler = $this->browser->request('GET', '/encaissements/nouveau');
        $values = $crawler->selectButton('Enregistrer l\'encaissement')->form()->getPhpValues();
        $values['encaissement'] = array_merge($values['encaissement'], ['dateEncaissement' => date('Y-m-d'), 'client' => (string) $client->getId(), 'montantTtc' => '1000', 'nature' => 'cadeau']);
        $this->browser->request('POST', '/encaissements/nouveau', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em->getRepository(Encaissement::class)->count([]));
    }

    public function testSynthese(): void
    {
        $a = $this->client('Alpha', '111');
        $this->login($this->user('agent@procom.pro', User::ROLE_AGENT));
        $this->submitNew($a, ['ttc' => '118000', 'especes' => '117000', 'cheque' => '0']);

        $this->browser->request('GET', '/encaissements/synthese');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Par mois');
        self::assertSelectorTextContains('body', 'Par client');
        self::assertSelectorTextContains('body', "118\u{00A0}000");
    }

    public function testExports(): void
    {
        $a = $this->client('=HYPERLINK("http://evil")', '111');
        $this->login($this->user('agent@procom.pro', User::ROLE_AGENT));
        $this->submitNew($a, ['ttc' => '118000', 'especes' => '117000', 'cheque' => '0']);

        $this->browser->request('GET', '/encaissements/export.xlsx');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', $this->browser->getResponse()->headers->get('Content-Type'));
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $this->browser->getResponse()->getContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);
        self::assertSame('=HYPERLINK("http://evil")', $sheet->getCell('B6')->getValue());
        self::assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $sheet->getCell('B6')->getDataType());
        self::assertSame('Paiement de facture', $sheet->getCell('C6')->getValue());
        self::assertEquals(118000, $sheet->getCell('D6')->getValue());
        self::assertEquals(117000, $sheet->getCell('I7')->getValue());

        $this->browser->request('GET', '/encaissements/export.pdf?client='.$a->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->browser->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', $this->browser->getResponse()->getContent());
    }

    public function testSeulLAdminModifieLesParametresFiscaux(): void
    {
        $this->login($this->user('agent@procom.pro', User::ROLE_AGENT));
        $this->browser->request('GET', '/admin/fiscal');
        self::assertResponseStatusCodeSame(403);

        $this->login($this->user('admin@procom.pro', User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/admin/fiscal');
        self::assertResponseIsSuccessful();
        $this->browser->submit($crawler->selectButton('Enregistrer')->form(['fiscal_setting[tauxAibSansIfu]' => '7.5']));
        self::assertResponseRedirects('/admin/fiscal');
        $this->em->clear();
        self::assertSame('7.50', $this->em->getRepository(FiscalSetting::class)->findOneBy([])->getTauxAibSansIfu());
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'fiscal.update']));
    }

    public function testLesVisiteursAnonymesSontRedirigesVersLaConnexion(): void
    {
        $this->browser->request('GET', '/encaissements');
        self::assertResponseRedirects('/login');
    }

    private function submitNew(Client $client, array $amounts): void
    {
        $crawler = $this->browser->request('GET', '/encaissements/nouveau');
        $this->browser->submit($crawler->selectButton('Enregistrer l\'encaissement')->form([
            'encaissement[dateEncaissement]' => date('Y-m-d'),
            'encaissement[client]' => (string) $client->getId(),
            'encaissement[montantTtc]' => $amounts['ttc'],
            'encaissement[montantEspecesMomo]' => $amounts['especes'],
            'encaissement[montantChequeVirement]' => $amounts['cheque'],
            'encaissement[nature]' => $amounts['nature'] ?? 'facture',
        ]));
    }

    private function encaissement(): Encaissement
    {
        $this->em->clear(); // le noyau redémarre entre deux requêtes : repartir de la base

        return $this->em->getRepository(Encaissement::class)->findOneBy([]) ?? self::fail('Aucun encaissement.');
    }

    private function client(string $name, ?string $ifu = null): Client
    {
        $client = (new Client())->setName($name)->setIfu($ifu);
        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    private function user(string $email, string $role): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = (new User())->setEmail($email)->setFullName(ucfirst(strstr($email, '@', true)))->setRole($this->em->getRepository(Role::class)->findOneBy(['code' => $role]))->setPassword('x')->setTotpSecret('x');
            $this->em->persist($user);
            $this->em->flush();
        }

        return $user;
    }

    private function login(User $user): void
    {
        $this->browser->loginUser($user);
    }
}
