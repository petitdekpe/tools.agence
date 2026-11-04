<?php

namespace App\Tests\Controller;

use App\Entity\ActivityLog;
use App\Entity\Personnel;
use App\Entity\User;
use App\Security\Permission;
use App\Service\AccountProvisioner;
use App\Tests\AppWebTestCase;

/** Comptes créés depuis le personnel, mot de passe temporaire par e-mail, récupération quand l'authentificateur est perdu. */
class AccountTest extends AppWebTestCase
{
    public function testCreationDUnCompteDepuisUneFiche(): void
    {
        $person = $this->personnel('Koffi', 'Mariam');
        $person->setEmail('mariam@procom.pro');
        $this->em->flush();

        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/personnel/'.$person->getId());
        $this->browser->click($crawler->selectLink('Créer un compte')->link());
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('account_invitation[email]', 'mariam@procom.pro');

        $this->browser->submit($this->browser->getCrawler()->selectButton('Créer le compte et envoyer le mot de passe')->form([
            'account_invitation[role]' => (string) $this->role(User::ROLE_AGENT)->getId(),
        ]));
        self::assertResponseRedirects('/personnel/'.$person->getId());

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertEmailAddressContains($mail, 'To', 'mariam@procom.pro');
        self::assertMatchesRegularExpression('/Mot de passe temporaire : (\S{12})\n/', $mail->getTextBody());

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'mariam@procom.pro']);
        self::assertSame('Mariam KOFFI', $user->getFullName());
        self::assertSame(User::ROLE_AGENT, $user->getRole()->getCode());
        self::assertFalse($user->hasTwoFactor());
        self::assertSame($user->getId(), $this->fresh(Personnel::class, $person->getId())->getUser()->getId());
        $hours = ($user->getPasswordExpiresAt()->getTimestamp() - time()) / 3600;
        self::assertEqualsWithDelta(AccountProvisioner::PASSWORD_VALID_HOURS, $hours, 0.1);
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'user.create']));
    }

    public function testSeulUnAdminCreeUnCompte(): void
    {
        $person = $this->personnel();
        $this->login($this->user(User::ROLE_DIRECTION)); // gère le personnel mais pas les comptes
        $this->browser->request('GET', '/admin/users/depuis-personnel/'.$person->getId());
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('GET', '/personnel/'.$person->getId());
        self::assertSelectorTextNotContains('body', 'Créer un compte');
    }

    public function testEmailHorsDomaineEtCompteDejaExistant(): void
    {
        $person = $this->personnel();
        $this->user(User::ROLE_AGENT, 'pris@procom.pro');
        $this->login($this->user(User::ROLE_ADMIN));

        $role = (string) $this->role(User::ROLE_AGENT)->getId();
        $crawler = $this->browser->request('GET', '/admin/users/depuis-personnel/'.$person->getId());
        $this->browser->submit($crawler->selectButton('Créer le compte et envoyer le mot de passe')->form(['account_invitation[email]' => 'x@gmail.com', 'account_invitation[role]' => $role]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Seuls les e-mails');

        $crawler = $this->browser->request('GET', '/admin/users/depuis-personnel/'.$person->getId());
        $this->browser->submit($crawler->selectButton('Créer le compte et envoyer le mot de passe')->form(['account_invitation[email]' => 'pris@procom.pro', 'account_invitation[role]' => $role]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Un compte existe déjà');
        self::assertEmailCount(0);
        self::assertNull($this->fresh(Personnel::class, $person->getId())->getUser());
    }

    public function testPasDeCompteEnDoubleNiPourUnePersonnePartie(): void
    {
        $withAccount = $this->personnel('Deja', 'Un');
        $withAccount->setUser($this->user(User::ROLE_AGENT, 'deja@procom.pro'));
        $gone = $this->personnel('Parti', 'Lou', '-3 days');
        $this->em->flush();

        $this->login($this->user(User::ROLE_ADMIN));
        foreach ([$withAccount, $gone] as $person) {
            $this->browser->request('GET', '/admin/users/depuis-personnel/'.$person->getId());
            self::assertResponseRedirects('/personnel/'.$person->getId());
        }
    }

    public function testPremiereConnexionAvecLeMotDePasseTemporaire(): void
    {
        $password = $this->createdAccountPassword('nouveau@procom.pro');

        $crawler = $this->browser->request('GET', '/login');
        $this->browser->submit($crawler->selectButton('Se connecter')->form(['email' => 'nouveau@procom.pro', 'password' => $password, 'code' => '']));
        self::assertResponseRedirects('/');
        $this->browser->followRedirect();
        self::assertResponseRedirects('/2fa/setup');
    }

    public function testLeMotDePasseTemporaireExpire(): void
    {
        $password = $this->createdAccountPassword('expire@procom.pro');
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'expire@procom.pro']);
        $user->setPasswordExpiresAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $crawler = $this->browser->request('GET', '/login');
        $this->browser->submit($crawler->selectButton('Se connecter')->form(['email' => 'expire@procom.pro', 'password' => $password, 'code' => '']));
        self::assertResponseRedirects('/login');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.error', 'a expiré');
    }

    public function testRenvoiDuMotDePasseSeulementAvantLActivationDu2fa(): void
    {
        $pending = $this->user(User::ROLE_AGENT, 'attente@procom.pro')->setTotpSecret(null);
        $active = $this->user(User::ROLE_AGENT, 'actif@procom.pro');
        $this->em->flush();
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/admin/users');
        $this->browser->submit($crawler->filter('form[action$="/'.$pending->getId().'/resend-password"]')->form());
        self::assertResponseRedirects('/admin/users');
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', 'attente@procom.pro');
        self::assertTrue($this->fresh(User::class, $pending->getId())->getPasswordExpiresAt() > new \DateTimeImmutable('+47 hours'));

        $this->browser->request('POST', '/admin/users/'.$active->getId().'/resend-password', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403); // jeton invalide, rien d'envoyé
        self::assertEmailCount(0);
    }

    public function testReinitialisationAdminEnvoieUnNouveauMotDePasse(): void
    {
        $target = $this->user(User::ROLE_AGENT, 'perdu@procom.pro');
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/admin/users');
        $this->browser->submit($crawler->filter('form[action$="/'.$target->getId().'/reset-2fa"]')->form());

        self::assertEmailCount(1);
        self::assertStringContainsString('réinitialisé', self::getMailerMessage()->getTextBody());
        $fresh = $this->fresh(User::class, $target->getId());
        self::assertFalse($fresh->hasTwoFactor());
        self::assertNotNull($fresh->getPasswordExpiresAt());
    }

    public function testRecuperationParLienEnvoyeParEmail(): void
    {
        $user = $this->user(User::ROLE_AGENT, 'agent@procom.pro');

        $crawler = $this->browser->request('GET', '/login/recovery');
        $this->browser->submit($crawler->selectButton('Envoyer le lien')->form(['email' => 'agent@procom.pro']));
        self::assertSelectorTextContains('.alert.success', 'Si un compte correspond');
        self::assertEmailCount(1);
        self::assertMatchesRegularExpression('#http://localhost(/login/recovery/[a-f0-9]{64})#', $body = self::getMailerMessage()->getTextBody());
        preg_match('#http://localhost(/login/recovery/[a-f0-9]{64})#', $body, $m);
        $path = $m[1];
        self::assertTrue($this->fresh(User::class, $user->getId())->hasTwoFactor(), 'demander le lien ne change rien');

        // Ouvrir le lien (GET) ne consomme rien : seul le bouton confirme.
        $crawler = $this->browser->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->fresh(User::class, $user->getId())->hasTwoFactor());

        $this->browser->submit($this->browser->getCrawler()->selectButton('Réinitialiser et recevoir un mot de passe')->form());
        self::assertResponseRedirects('/login');
        self::assertEmailCount(1);
        self::assertStringContainsString('Mot de passe temporaire', self::getMailerMessage()->getTextBody());
        $fresh = $this->fresh(User::class, $user->getId());
        self::assertFalse($fresh->hasTwoFactor());
        self::assertNull($fresh->getRecoveryTokenHash());

        $this->browser->request('GET', $path);
        self::assertResponseStatusCodeSame(410, 'le lien ne sert qu\'une fois');
    }

    public function testRecuperationNeRevelePasLExistenceDuCompte(): void
    {
        $this->user(User::ROLE_AGENT, 'agent@procom.pro')->setActive(false);
        $this->em->flush();

        foreach (['inconnu@procom.pro', 'agent@procom.pro', 'dehors@gmail.com'] as $email) {
            $crawler = $this->browser->request('GET', '/login/recovery');
            $this->browser->submit($crawler->selectButton('Envoyer le lien')->form(['email' => $email]));
            self::assertSelectorTextContains('.alert.success', 'Si un compte correspond');
            self::assertEmailCount(0);
        }
    }

    public function testUnLienExpireOuInventeEstRefuse(): void
    {
        $user = $this->user(User::ROLE_AGENT, 'agent@procom.pro');
        $token = bin2hex(random_bytes(32));
        $user->setRecoveryToken(hash('sha256', $token), new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $this->browser->request('GET', '/login/recovery/'.$token);
        self::assertResponseStatusCodeSame(410);
        $this->browser->request('GET', '/login/recovery/'.str_repeat('a', 64));
        self::assertResponseStatusCodeSame(410);
        $this->browser->request('GET', '/login/recovery/court');
        self::assertResponseStatusCodeSame(404);
        self::assertTrue($this->fresh(User::class, $user->getId())->hasTwoFactor());
    }

    public function testLeJournalNeMontrePasLePersonnelSansLeDroitDeLeVoir(): void
    {
        $person = $this->personnel('Confidentiel', 'Zoe');
        $this->login($this->user(User::ROLE_DIRECTION));
        $crawler = $this->browser->request('GET', '/personnel/'.$person->getId().'/modifier');
        $this->browser->submit($crawler->selectButton('Enregistrer')->form(['personnel[jobTitle]' => 'Directrice']));

        $audit = $this->customRole('Audit', [Permission::ADMIN_ACCESS, Permission::ACTIVITY_VIEW]);
        $this->login($this->user($audit->getCode(), 'audit@procom.pro'));
        $this->browser->request('GET', '/admin/activity');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Fiche personnel modifiée');
        self::assertSelectorTextNotContains('body', 'Directrice');
        $this->browser->request('GET', '/admin/activity?category=personnel');
        self::assertSelectorTextNotContains('body', 'Fiche personnel modifiée');

        $this->login($this->user(User::ROLE_DIRECTION, 'dir2@procom.pro')); // a personnel.view par défaut
        $this->browser->request('GET', '/admin/activity?category=personnel');
        self::assertSelectorTextContains('body', 'Fiche personnel modifiée');
    }

    /** Crée un compte comme le fait l'administration et rend le mot de passe lu dans l'e-mail. */
    private function createdAccountPassword(string $email): string
    {
        $provisioner = static::getContainer()->get(AccountProvisioner::class);
        $provisioner->createAccount($this->personnel(), $email, $this->role(User::ROLE_AGENT));
        self::assertEmailCount(1);
        self::assertSame(1, preg_match('/Mot de passe temporaire : (\S+)\n/', self::getMailerMessage()->getTextBody(), $m));

        return $m[1];
    }
}
