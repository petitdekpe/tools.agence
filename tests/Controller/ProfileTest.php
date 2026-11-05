<?php

namespace App\Tests\Controller;

use App\Entity\ActivityLog;
use App\Entity\Personnel;
use App\Entity\PersonnelDocument;
use App\Entity\User;
use App\Service\PersonnelFiles;
use App\Tests\AppWebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Page profil : informations personnelles, documents et photos, et accès aux fichiers. */
class ProfileTest extends AppWebTestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        $files = static::getContainer()->get(PersonnelFiles::class);
        foreach ($this->em->getRepository(PersonnelDocument::class)->findAll() as $document) {
            @unlink($files->pathOf($document));
        }
        array_map('unlink', array_filter($this->temp, 'is_file'));
        parent::tearDown();
    }

    public function testLAvatarMeneAuProfil(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $crawler = $this->browser->request('GET', '/');
        self::assertGreaterThan(0, $crawler->filter('.usermenu a[href="/profil"]')->count());
    }

    public function testUnCompteSansFicheVoitSesInformationsEtUnMessage(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $this->browser->request('GET', '/profil');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.profile-head', 'admin@procom.pro');
        self::assertSelectorTextContains('.banner', 'Aucune fiche du personnel');
        self::assertSelectorNotExists('form.form');
    }

    public function testLaPersonneCompleteSonProfil(): void
    {
        [$user, $person] = $this->agentWithFiche();
        $this->login($user);

        $crawler = $this->browser->request('GET', '/profil');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.profile-missing', 'date de naissance');
        // Les indicatifs sont proposés pour chaque numéro (téléphone et deux contacts d'urgence).
        self::assertCount(3, $crawler->filter('select.phone-code'));
        self::assertGreaterThan(40, $crawler->filter('select[name="profile[phone][code]"] option')->count());

        $values = $crawler->filter('form.form')->form()->getPhpValues();
        $values['profile'] = array_merge($values['profile'], [
            'phone' => ['code' => '+228', 'number' => '90 12 34 56'],
            'personalEmail' => 'Awa.Perso@gmail.com',
            'birthDate' => '1995-03-25',
            'adventureStartedAt' => '2021-06-01',
            'residence' => 'Fidjrossè, Cotonou',
            'residenceMapUrl' => 'https://maps.app.goo.gl/abc123',
            'emergency1Name' => 'Koffi, frère',
            'emergency1Phone' => ['code' => '+229', 'number' => '0197000000'],
            'emergency2Name' => 'Mariam, amie',
            'emergency2Phone' => ['code' => '+33', 'number' => '612345678'],
            'social_instagram' => '@awa.dupont',
            'social_linkedin' => 'https://www.linkedin.com/in/awa-dupont',
        ]);
        $this->browser->request('POST', '/profil', $values);
        self::assertResponseRedirects('/profil');

        $fresh = $this->fresh(Personnel::class, $person->getId());
        self::assertSame('+228 90 12 34 56', $fresh->getPhone());
        self::assertSame('awa.perso@gmail.com', $fresh->getPersonalEmail());
        self::assertSame('1995-03-25', $fresh->getBirthDate()->format('Y-m-d'));
        self::assertSame('Bélier', $fresh->getZodiac()['label']);
        self::assertSame('+33 612345678', $fresh->getEmergency2Phone());
        self::assertSame('https://maps.app.goo.gl/abc123', $fresh->getMapsLink());
        // MySQL réordonne les clés d'un champ JSON : on compare sans tenir compte de l'ordre.
        self::assertEquals(['instagram' => '@awa.dupont', 'linkedin' => 'https://www.linkedin.com/in/awa-dupont'], $fresh->getSocials());
        self::assertSame('https://www.instagram.com/awa.dupont', $fresh->getSocialLinks()[0]['url']);

        $log = $this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'personnel.profile']);
        self::assertContains('naissance', $log->getDetails()['champs']);

        $this->browser->followRedirect();
        self::assertSelectorTextContains('.zodiac', 'Bélier');
    }

    public function testChampsInvalidesRefuses(): void
    {
        [$user] = $this->agentWithFiche();
        $this->login($user);
        $crawler = $this->browser->request('GET', '/profil');
        $values = $crawler->filter('form.form')->form()->getPhpValues();
        $values['profile'] = array_merge($values['profile'], [
            'phone' => ['code' => '+229', 'number' => 'abc'],
            'birthDate' => '2999-01-01',
            'adventureStartedAt' => '2999-01-01',
            'residenceMapUrl' => 'javascript:alert(1)',
            'social_facebook' => 'javascript:alert(1)',
        ]);
        $this->browser->request('POST', '/profil', $values);
        self::assertResponseStatusCodeSame(422);
        foreach (['Numéro invalide', 'passée', 'dans le futur', 'Lien invalide', 'Adresse invalide'] as $message) {
            self::assertSelectorTextContains('body', $message);
        }
    }

    public function testDocumentsEtPhotosAvecLimiteDeQuatrePhotos(): void
    {
        [$user, $person] = $this->agentWithFiche();
        $this->login($user);

        $this->postProfile(['identity' => $this->upload('pdf', 'identite.pdf'), 'cv' => $this->upload('pdf', 'cv.pdf'), 'photos' => [$this->upload('png', 'a.png'), $this->upload('png', 'b.png'), $this->upload('png', 'c.png')]]);
        self::assertResponseRedirects('/profil');
        $docs = $this->em->getRepository(PersonnelDocument::class)->findBy(['personnel' => $person]);
        self::assertCount(5, $docs);

        // Un nouveau CV remplace l'ancien ; la 4e photo passe, la 5e est refusée.
        $this->postProfile(['cv' => $this->upload('pdf', 'cv2.pdf'), 'photos' => [$this->upload('png', 'd.png')]]);
        self::assertResponseRedirects('/profil');
        self::assertSame(1, $this->em->getRepository(PersonnelDocument::class)->count(['personnel' => $person, 'kind' => PersonnelDocument::CV]));
        self::assertSame(4, $this->em->getRepository(PersonnelDocument::class)->count(['personnel' => $person, 'kind' => PersonnelDocument::PHOTO]));

        $this->postProfile(['photos' => [$this->upload('png', 'e.png')]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'déjà 4 photos');
        self::assertSame(4, $this->em->getRepository(PersonnelDocument::class)->count(['personnel' => $person, 'kind' => PersonnelDocument::PHOTO]));

        // Un fichier d'un autre format (un script renommé en .pdf) est refusé sur son contenu.
        $this->postProfile(['identity' => $this->upload('php', 'faux.pdf')]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testLesFichiersNeSontServisQuAuxAyantsDroit(): void
    {
        [$user, $person] = $this->agentWithFiche();
        $this->login($user);
        $this->postProfile(['identity' => $this->upload('pdf', 'identite.pdf'), 'photos' => [$this->upload('png', 'a.png')]]);
        $identity = $this->em->getRepository(PersonnelDocument::class)->findOneBy(['kind' => PersonnelDocument::IDENTITY]);
        $photo = $this->em->getRepository(PersonnelDocument::class)->findOneBy(['kind' => PersonnelDocument::PHOTO]);

        // Le propriétaire lit ses fichiers, sans qu'on le note dans le journal.
        $this->browser->request('GET', '/personnel/documents/'.$identity->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->browser->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->browser->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'personnel.document']));

        // Un autre agent n'y a pas accès, pas même aux photos sans le droit « personnel.view ».
        $this->login($this->user(User::ROLE_AGENT, 'collegue@procom.pro'));
        foreach ([$identity, $photo] as $document) {
            $this->browser->request('GET', '/personnel/documents/'.$document->getId());
            self::assertResponseStatusCodeSame(404);
        }

        // Les RH lisent tout, et la consultation d'une pièce est tracée.
        $this->login($this->user(User::ROLE_RH_FINANCE));
        $this->browser->request('GET', '/personnel/documents/'.$identity->getId());
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'personnel.document']));
        $crawler = $this->browser->request('GET', '/personnel/'.$person->getId());
        self::assertSelectorTextContains('body', 'Profil personnel');

        // Le matériel peut voir la fiche mais pas le profil personnel ni les pièces.
        $this->login($this->user(User::ROLE_MATERIEL));
        $this->browser->request('GET', '/personnel/'.$person->getId());
        self::assertSelectorTextNotContains('body', 'Profil personnel');
        $this->browser->request('GET', '/personnel/documents/'.$identity->getId());
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/personnel/documents/'.$photo->getId());
        self::assertResponseIsSuccessful();
    }

    public function testSupprimerSesFichiersEtSeulementLesSiens(): void
    {
        [$user] = $this->agentWithFiche();
        $this->login($user);
        $this->postProfile(['cv' => $this->upload('pdf', 'cv.pdf')]);
        $cv = $this->em->getRepository(PersonnelDocument::class)->findOneBy(['kind' => PersonnelDocument::CV]);
        $path = static::getContainer()->get(PersonnelFiles::class)->pathOf($cv);
        self::assertFileExists($path);

        $this->login($this->user(User::ROLE_AGENT, 'intrus@procom.pro'));
        $this->browser->request('POST', '/profil/documents/'.$cv->getId().'/supprimer');
        self::assertResponseStatusCodeSame(404);

        $this->login($user);
        $crawler = $this->browser->request('GET', '/profil');
        $this->browser->submit($crawler->filter('form#delete-'.$cv->getId())->form());
        self::assertResponseRedirects('/profil');
        self::assertSame(0, $this->em->getRepository(PersonnelDocument::class)->count([]));
        self::assertFileDoesNotExist($path);
    }

    public function testLeTelephoneDesRHAUnIndicatif(): void
    {
        $this->login($this->user(User::ROLE_RH_FINANCE));
        $person = $this->personnel();
        $crawler = $this->browser->request('GET', '/personnel/'.$person->getId().'/modifier');
        $values = $crawler->filter('form.form')->form()->getPhpValues();
        $values['personnel']['phone'] = ['code' => '+221', 'number' => '77 123 45 67'];
        $this->browser->request('POST', '/personnel/'.$person->getId().'/modifier', $values);
        self::assertResponseRedirects('/personnel/'.$person->getId());
        self::assertSame('+221 77 123 45 67', $this->fresh(Personnel::class, $person->getId())->getPhone());
    }

    /** @return array{User, Personnel} */
    private function agentWithFiche(): array
    {
        $user = $this->user(User::ROLE_AGENT, 'awa@procom.pro');
        $person = $this->personnel();
        $person->setUser($user);
        $this->em->flush();

        return [$user, $person];
    }

    /** @param array<string, mixed> $files */
    private function postProfile(array $files): void
    {
        $crawler = $this->browser->request('GET', '/profil');
        $values = $crawler->filter('form.form')->form()->getPhpValues();
        $this->browser->request('POST', '/profil', $values, ['profile' => $files]);
    }

    private function upload(string $type, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'prf');
        $this->temp[] = $path;
        file_put_contents($path, match ($type) {
            'pdf' => "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
            'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
            default => '<?php echo "pwned";',
        });

        return new UploadedFile($path, $name, null, null, true);
    }
}
