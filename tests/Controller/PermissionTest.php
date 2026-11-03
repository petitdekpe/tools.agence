<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\Permission;
use App\Service\PermissionManager;
use App\Tests\AppWebTestCase;

/** Ce que chaque rôle voit et peut faire, pour les rôles d'origine comme pour les rôles créés. */
class PermissionTest extends AppWebTestCase
{
    public function testPermissionsParDefautDesRolesDOrigine(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        foreach (['/', '/materiel', '/encaissements', '/correcteur-media'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        foreach (['/personnel', '/personnel/nouveau', '/admin', '/admin/users', '/admin/activity', '/admin/roles'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->login($this->user(User::ROLE_DIRECTION));
        foreach (['/personnel', '/personnel/nouveau', '/admin', '/admin/users', '/admin/activity', '/encaissements'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        foreach (['/admin/roles', '/admin/users/new', '/admin/settings', '/admin/fiscal'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->login($this->user(User::ROLE_ADMIN));
        foreach (['/personnel', '/materiel', '/encaissements', '/correcteur-media', '/admin', '/admin/users', '/admin/users/new', '/admin/roles', '/admin/roles/nouveau', '/admin/activity', '/admin/settings', '/admin/fiscal'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    public function testLAccueilNeMontreQueLesOutilsAutorises(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $this->browser->request('GET', '/');
        self::assertSelectorTextContains('.cards', 'Gestion du matériel');
        self::assertSelectorTextContains('.cards', 'Suivi des encaissements');
        self::assertSelectorTextContains('.cards', 'Correcteur média');
        self::assertSelectorTextNotContains('.cards', 'Gestion du personnel');
        self::assertSelectorNotExists('a[href="/admin"]');

        $this->login($this->user(User::ROLE_ADMIN));
        $this->browser->request('GET', '/');
        self::assertSelectorTextContains('.cards', 'Gestion du personnel');
        self::assertSelectorExists('a[href="/admin"]');
    }

    public function testUnRoleCreeSeulementMaterielNeVoitQueLeMateriel(): void
    {
        $role = $this->customRole('Magasinier', [Permission::MATERIEL_VIEW]);
        $this->login($this->user($role->getCode(), 'magasinier@procom.pro'));

        $this->browser->request('GET', '/');
        self::assertSelectorTextContains('.cards', 'Gestion du matériel');
        self::assertSelectorTextNotContains('.cards', 'Suivi des encaissements');
        self::assertSelectorTextNotContains('.cards', 'Correcteur média');
        self::assertSelectorTextNotContains('.cards', 'Gestion du personnel');

        $this->browser->request('GET', '/materiel');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href*="/materiel/nouveau"]');
        foreach (['/materiel/nouveau', '/materiel/export.xlsx', '/encaissements', '/encaissements/synthese', '/encaissements/clients', '/encaissements/export.pdf', '/correcteur-media', '/correcteur-media/api/corrections', '/espocrm', '/personnel', '/admin', '/admin/users', '/admin/activity', '/admin/roles'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testUnRoleCreeSansAucunePermissionSeConnecteMaisNeVoitRien(): void
    {
        $role = $this->customRole('Observateur');
        $this->login($this->user($role->getCode(), 'obs@procom.pro'));

        $this->browser->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.cards a');
        $this->browser->request('GET', '/materiel');
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLEspaceAdministrationSeDelegueParMorceaux(): void
    {
        $role = $this->customRole('Auditeur', [Permission::ACTIVITY_VIEW]);
        $this->login($this->user($role->getCode(), 'auditeur@procom.pro'));

        // « activity.view » entraîne l'accès à l'espace, pas la liste des utilisateurs.
        $crawler = $this->browser->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.adm-nav', 'Journal d\'activité');
        self::assertSelectorTextNotContains('.adm-nav', 'Utilisateurs');
        self::assertSelectorTextNotContains('.adm-nav', 'Rôles');
        self::assertSelectorTextNotContains('.adm-nav', 'Réglages du site');
        $this->browser->request('GET', '/admin/activity');
        self::assertResponseIsSuccessful();
        $this->browser->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testToutePermissionAccordeeEntraineLAccesAuxOutils(): void
    {
        $role = $this->customRole('Livreur', [Permission::MATERIEL_ASSIGN, Permission::ENCAISSEMENT_EXPORT]);
        $granted = static::getContainer()->get(PermissionManager::class)->granted($role);

        self::assertContains(Permission::MATERIEL_VIEW, $granted);
        self::assertContains(Permission::ENCAISSEMENT_VIEW, $granted);
        self::assertNotContains(Permission::MATERIEL_EDIT, $granted);
        self::assertNotContains(Permission::PERSONNEL_VIEW, $granted);
    }

    public function testLeRoleAdminNeSeRestreintPas(): void
    {
        $manager = static::getContainer()->get(PermissionManager::class);
        $admin = $this->role(User::ROLE_ADMIN);
        foreach (Permission::all() as $permission) {
            self::assertTrue($manager->roleHas($admin, $permission), $permission);
        }
        self::assertFalse($manager->roleHas($admin, 'permission.inconnue'));

        $this->expectException(\LogicException::class);
        $manager->save($admin, []);
    }

    public function testPermissionsInconnuesIgnorees(): void
    {
        $manager = static::getContainer()->get(PermissionManager::class);
        $role = $this->customRole('Test', [Permission::PERSONNEL_VIEW, 'nimporte.quoi', 'ROLE_ADMIN']);

        self::assertSame([Permission::PERSONNEL_VIEW], $manager->granted($role));
        self::assertFalse($manager->roleHas($role, 'ROLE_ADMIN'));
    }

    public function testLaSuppressionDEncaissementSeDelegue(): void
    {
        $agent = $this->user(User::ROLE_AGENT);
        $this->login($agent);
        $this->browser->request('POST', '/encaissements/1/supprimer', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $role = $this->customRole('Comptable', [Permission::ENCAISSEMENT_DELETE]);
        $this->login($this->user($role->getCode(), 'comptable@procom.pro'));
        $this->browser->request('POST', '/encaissements/1/supprimer', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404, 'autorisé : la page répond sur l\'encaissement, qui n\'existe pas');
    }

    public function testSansConnexionToutRedirigeVersLaConnexion(): void
    {
        foreach (['/', '/materiel', '/admin', '/admin/roles'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseRedirects('/login', null, $url);
        }
    }
}
