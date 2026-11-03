<?php

namespace App\Tests\Controller;

use App\Entity\ActivityLog;
use App\Entity\Role;
use App\Entity\RolePermission;
use App\Entity\User;
use App\Repository\RoleRepository;
use App\Security\Permission;
use App\Service\PermissionManager;
use App\Service\RoleManager;
use App\Tests\AppWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;

class RoleTest extends AppWebTestCase
{
    public function testLAdminCreeUnRole(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $this->submitRole('/admin/roles/nouveau', ['label' => 'Chef de projet', 'description' => 'Pilote les projets clients'], [Permission::ENCAISSEMENT_EDIT, Permission::PERSONNEL_VIEW]);
        self::assertResponseRedirects('/admin/roles');

        $role = $this->em->getRepository(Role::class)->findOneBy(['label' => 'Chef de projet']);
        self::assertNotNull($role);
        self::assertSame('ROLE_CHEF_DE_PROJET', $role->getCode());
        self::assertFalse($role->isSystem());
        self::assertSame('Pilote les projets clients', $role->getDescription());

        // « Saisir un encaissement » entraîne de lui-même la consultation des encaissements.
        $granted = static::getContainer()->get(PermissionManager::class)->granted($role);
        self::assertEqualsCanonicalizing([Permission::ENCAISSEMENT_VIEW, Permission::ENCAISSEMENT_EDIT, Permission::PERSONNEL_VIEW], $granted);

        $log = $this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'role.create']);
        self::assertSame('Chef de projet', $log->getDetails()['nom']);
        self::assertSame('ROLE_CHEF_DE_PROJET', $log->getDetails()['code']);

        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.success', 'Rôle « Chef de projet » créé');
        self::assertSelectorTextContains('tbody', 'Chef de projet');
    }

    public function testNomObligatoireEtUnique(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));

        $this->submitRole('/admin/roles/nouveau', ['label' => ' '], []);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le nom du rôle est obligatoire.');

        // Le nom d'un rôle système compte aussi, sans tenir compte de la casse.
        $this->submitRole('/admin/roles/nouveau', ['label' => 'agent'], []);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Un rôle porte déjà ce nom.');

        $this->customRole('Stagiaire');
        $this->submitRole('/admin/roles/nouveau', ['label' => 'Stagiaire'], []);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(4, $this->em->getRepository(Role::class)->count([]), 'trois rôles système et un seul rôle créé');
    }

    public function testLeCodeSeDeduitDuNomSansCollision(): void
    {
        $manager = static::getContainer()->get(RoleManager::class);

        self::assertSame('ROLE_CHARGE_D_ETUDES', $manager->uniqueCode('Chargé d\'études'));
        self::assertSame('ROLE_RESPONSABLE_RH', $manager->uniqueCode('  Responsable   RH '));

        $this->customRole('Chef de projet');
        self::assertSame('ROLE_CHEF_DE_PROJET_2', $manager->uniqueCode('chef-de-projet'), 'même code, nom différent');

        // Jamais un code que la sécurité utilise déjà.
        self::assertNotContains($manager->uniqueCode('Super admin'), ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_USER']);
        self::assertSame('ROLE_USER_2', $manager->uniqueCode('User'));
        self::assertSame('ROLE_ROLE', $manager->uniqueCode('!!!'));
    }

    public function testPartirDesPermissionsDUnRoleExistant(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $agent = $this->role(User::ROLE_AGENT);

        $crawler = $this->browser->request('GET', '/admin/roles/nouveau');
        self::assertCount(0, $crawler->filter('input[name="permissions[]"][checked]'), 'repartir de zéro par défaut');

        $crawler = $this->browser->request('GET', '/admin/roles/nouveau?copier='.$agent->getId());
        $checked = $crawler->filter('input[name="permissions[]"][checked]')->each(static fn (Crawler $c): string => $c->attr('value'));
        self::assertEqualsCanonicalizing(static::getContainer()->get(PermissionManager::class)->granted($agent), $checked);
        self::assertContains(Permission::MATERIEL_ASSIGN, $checked);
        self::assertNotContains(Permission::PERSONNEL_VIEW, $checked);

        // Le modèle ne se modifie pas en le copiant : le nouveau rôle part de ses propres cases.
        $this->submitRole('/admin/roles/nouveau?copier='.$agent->getId(), ['label' => 'Agent junior'], [Permission::MATERIEL_VIEW]);
        $junior = $this->em->getRepository(Role::class)->findOneBy(['label' => 'Agent junior']);
        self::assertSame([Permission::MATERIEL_VIEW], static::getContainer()->get(PermissionManager::class)->granted($junior));
        self::assertContains(Permission::MATERIEL_ASSIGN, static::getContainer()->get(PermissionManager::class)->granted($this->role(User::ROLE_AGENT)));
    }

    public function testModifierLesPermissionsPrendEffetImmediatementEtEstJournalise(): void
    {
        $role = $this->customRole('Magasinier', [Permission::MATERIEL_VIEW]);
        $worker = $this->user($role->getCode(), 'mag@procom.pro');
        $this->login($worker);
        $this->browser->request('GET', '/materiel/nouveau');
        self::assertResponseStatusCodeSame(403);

        $this->login($this->user(User::ROLE_ADMIN));
        $this->submitRole('/admin/roles/'.$role->getId().'/modifier', ['label' => 'Magasinier principal'], [Permission::MATERIEL_EDIT, Permission::ENCAISSEMENT_VIEW]);
        self::assertResponseRedirects('/admin/roles');

        $this->login($worker);
        $this->browser->request('GET', '/materiel/nouveau');
        self::assertResponseIsSuccessful();
        $this->browser->request('GET', '/encaissements');
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $role = $this->em->find(Role::class, $role->getId());
        self::assertSame('Magasinier principal', $role->getLabel());
        self::assertSame('ROLE_MAGASINIER', $role->getCode(), 'le code ne change jamais');

        $log = $this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'role.update']);
        self::assertSame(['nom' => 'Magasinier', 'description' => null], $log->getDetails()['avant']);
        self::assertSame('Magasinier principal', $log->getDetails()['apres']['nom']);
        self::assertContains('Créer et modifier du matériel', $log->getDetails()['permissions_ajoutees']);
        self::assertContains('Consulter les encaissements, clients et synthèses', $log->getDetails()['permissions_ajoutees']);
        self::assertSame([], $log->getDetails()['permissions_retirees']);
    }

    public function testSansChangementRienNEstJournalise(): void
    {
        $role = $this->customRole('Stable', [Permission::PERSONNEL_VIEW]);
        $this->login($this->user(User::ROLE_ADMIN));
        $this->submitRole('/admin/roles/'.$role->getId().'/modifier', ['label' => 'Stable'], [Permission::PERSONNEL_VIEW]);
        self::assertResponseRedirects('/admin/roles');
        $this->browser->followRedirect();

        self::assertSelectorTextContains('.alert.success', 'Aucun changement');
        self::assertNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'role.update']));
    }

    public function testLeRoleAdminNeSeRestreintPasMaisSeRenomme(): void
    {
        $admin = $this->role(User::ROLE_ADMIN);
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/admin/roles/'.$admin->getId().'/modifier');
        self::assertResponseIsSuccessful();
        $boxes = $crawler->filter('input[name="permissions[]"]');
        self::assertCount(\count(Permission::all()), $boxes);
        self::assertCount(\count(Permission::all()), $crawler->filter('input[name="permissions[]"][checked][disabled]'), 'tout coché et verrouillé');

        // Même en forgeant la requête, ses permissions ne bougent pas.
        $this->submitRole('/admin/roles/'.$admin->getId().'/modifier', ['label' => 'Administrateur'], []);
        self::assertResponseRedirects('/admin/roles');

        $this->em->clear();
        $admin = $this->em->find(Role::class, $admin->getId());
        self::assertSame('Administrateur', $admin->getLabel());
        self::assertSame(User::ROLE_ADMIN, $admin->getCode());
        $manager = static::getContainer()->get(PermissionManager::class);
        foreach (Permission::all() as $permission) {
            self::assertTrue($manager->roleHas($admin, $permission), $permission);
        }
        self::assertSame(0, $this->em->getRepository(RolePermission::class)->count(['role' => $admin]));
    }

    public function testLesRolesSystemeNeSeSupprimentPas(): void
    {
        $manager = static::getContainer()->get(RoleManager::class);
        foreach ([User::ROLE_ADMIN, User::ROLE_AGENT, User::ROLE_DIRECTION] as $code) {
            try {
                $manager->delete($this->role($code));
                self::fail("$code a été supprimé.");
            } catch (\DomainException $e) {
                self::assertStringContainsString('fait partie de l\'application', $e->getMessage());
            }
        }
        self::assertSame(3, $this->em->getRepository(Role::class)->count([]));

        // Pas de bouton « Supprimer » pour eux dans la liste, et un jeton ne suffirait pas à les atteindre.
        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/admin/roles');
        self::assertCount(0, $crawler->filter('form[action$="/supprimer"]'));
        $this->browser->request('POST', '/admin/roles/'.$this->role(User::ROLE_AGENT)->getId().'/supprimer', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnRoleAttribueNeSeSupprimePas(): void
    {
        $role = $this->customRole('Stagiaire', [Permission::MATERIEL_VIEW]);
        $this->user($role->getCode(), 'stage1@procom.pro');
        $this->user($role->getCode(), 'stage2@procom.pro');

        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/admin/roles');
        self::assertSelectorTextContains('tbody', '2');
        $this->browser->submit($crawler->filter('form[action$="/'.$role->getId().'/supprimer"]')->form());
        $this->browser->followRedirect();

        self::assertSelectorTextContains('.alert.error', 'encore attribué à 2 utilisateurs');
        self::assertNotNull($this->em->getRepository(Role::class)->find($role->getId()));
        self::assertNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'role.delete']));
    }

    public function testUnRoleLibreSeSupprimeAvecSesPermissions(): void
    {
        $role = $this->customRole('Temporaire', [Permission::PERSONNEL_VIEW, Permission::MATERIEL_VIEW]);
        $id = $role->getId();
        self::assertGreaterThan(0, $this->em->getRepository(RolePermission::class)->count(['role' => $role]));

        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/admin/roles');
        $this->browser->submit($crawler->filter('form[action$="/'.$id.'/supprimer"]')->form());
        self::assertResponseRedirects('/admin/roles');
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert.success', 'Rôle « Temporaire » supprimé');

        $this->em->clear();
        self::assertNull($this->em->getRepository(Role::class)->find($id));
        self::assertSame(0, $this->em->getRepository(RolePermission::class)->count([]), 'plus aucune permission orpheline');
        $log = $this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'role.delete']);
        self::assertSame('ROLE_TEMPORAIRE', $log->getDetails()['code']);
    }

    public function testJetonCsrfRequisPourSupprimer(): void
    {
        $role = $this->customRole('Protege');
        $this->login($this->user(User::ROLE_ADMIN));
        $this->browser->request('POST', '/admin/roles/'.$role->getId().'/supprimer', ['_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em->getRepository(Role::class)->find($role->getId()));
    }

    public function testSeulLAdminGereLesRolesMemeAvecTousLesDroits(): void
    {
        // Un rôle qui aurait toutes les permissions du catalogue ne gère pas pour autant les rôles :
        // sinon il pourrait s'attribuer n'importe quel droit.
        $boss = $this->customRole('Tout sauf admin', Permission::all());
        $agent = $this->role(User::ROLE_AGENT);
        $bossUser = $this->user($boss->getCode(), 'boss@procom.pro');
        $this->login($bossUser);

        foreach (['/admin/roles', '/admin/roles/nouveau', '/admin/roles/'.$boss->getId().'/modifier', '/admin/users/new', '/admin/users/'.$bossUser->getId().'/edit', '/admin/settings', '/admin/fiscal'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->browser->request('POST', '/admin/roles/nouveau', ['permissions' => [Permission::PERSONNEL_DELETE], 'role' => ['label' => 'Pirate']]);
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('POST', '/admin/roles/'.$boss->getId().'/supprimer', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('POST', '/admin/roles/'.$agent->getId().'/modifier', ['permissions' => Permission::all()]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em->getRepository(Role::class)->findOneBy(['label' => 'Pirate']));

        // Il consulte en revanche la liste des utilisateurs, sans bouton de gestion.
        $crawler = $this->browser->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href*="/admin/users/new"], a[href$="/edit"]'));
    }

    public function testLaListeMontreLesCompteursEtLesPermissions(): void
    {
        $role = $this->customRole('Magasinier', [Permission::MATERIEL_VIEW, Permission::MATERIEL_EDIT]);
        $this->user($role->getCode(), 'm1@procom.pro');
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/admin/roles');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('tbody tr')->reduce(static fn (Crawler $tr): bool => str_contains($tr->text(), 'Magasinier'))->first();
        self::assertStringContainsString('2 sur '.\count(Permission::all()), $row->text());
        self::assertStringContainsString('Créé', $row->text());
        self::assertStringContainsString('Toutes ('.\count(Permission::all()).')', $crawler->filter('tbody')->text());
        self::assertStringContainsString('Système', $crawler->filter('tbody')->text());
    }

    public function testAttribuerUnRoleCreeAUnUtilisateur(): void
    {
        $role = $this->customRole('Chef de projet', [Permission::ENCAISSEMENT_VIEW]);
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/admin/users/new');
        $options = $crawler->filter('select[name="user[role]"] option')->each(static fn (Crawler $o): string => trim($o->text()));
        self::assertContains('Chef de projet', $options);
        self::assertContains('Agent', $options);

        $form = $crawler->selectButton('Créer l\'utilisateur')->form([
            'user[fullName]' => 'Awa Koffi',
            'user[email]' => 'awa@procom.pro',
            'user[role]' => (string) $role->getId(),
            'user[plainPassword]' => 'motdepasse1',
        ]);
        $this->browser->submit($form);
        self::assertResponseRedirects('/admin/users');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'awa@procom.pro']);
        self::assertSame('ROLE_CHEF_DE_PROJET', $user->getRole()->getCode());
        self::assertSame('Chef de projet', $user->getRoleLabel());
        self::assertSame(['ROLE_CHEF_DE_PROJET', 'ROLE_USER'], $user->getRoles());
        self::assertFalse($user->isAdmin());

        $log = $this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'user.create']);
        self::assertSame('Chef de projet', $log->getDetails()['role']);

        $crawler = $this->browser->request('GET', '/admin/users');
        self::assertSelectorTextContains('tbody', 'Chef de projet');
    }

    public function testLeRoleEstObligatoireEtDoitExister(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/admin/users/new');
        $values = $crawler->selectButton('Créer l\'utilisateur')->form()->getPhpValues();
        $values['user'] = ['fullName' => 'X Y', 'email' => 'xy@procom.pro', 'plainPassword' => 'motdepasse1', 'role' => '', '_token' => $values['user']['_token']];
        $this->browser->request('POST', '/admin/users/new', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Choisissez un rôle.');

        $values['user']['role'] = '99999';
        $this->browser->request('POST', '/admin/users/new', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Ce rôle n\'existe pas.');
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'xy@procom.pro']));
    }

    public function testNouvelUtilisateurPartParDefautAgent(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/admin/users/new');
        self::assertSame((string) $this->role(User::ROLE_AGENT)->getId(), $crawler->selectButton('Créer l\'utilisateur')->form()['user[role]']->getValue());
    }

    public function testChangerLeRoleDUnUtilisateurLeDeconnecte(): void
    {
        $first = $this->customRole('Premier', [Permission::MATERIEL_VIEW]);
        $second = $this->customRole('Second', [Permission::PERSONNEL_VIEW]);
        $user = $this->user($first->getCode(), 'bob@procom.pro');

        $this->login($user);
        $this->browser->request('GET', '/materiel');
        self::assertResponseIsSuccessful();
        $this->browser->request('GET', '/materiel');
        self::assertResponseIsSuccessful('la session survit avec un rôle créé');

        $this->em->clear();
        $this->em->find(User::class, $user->getId())->setRole($this->em->find(Role::class, $second->getId()));
        $this->em->flush();

        $this->browser->request('GET', '/materiel');
        self::assertResponseRedirects('/login', null, 'son rôle a changé : il doit se reconnecter');
    }

    public function testLeDernierAdminNePeutPasChangerDeRole(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->login($admin);
        $crawler = $this->browser->request('GET', '/admin/users/'.$admin->getId().'/edit');
        $this->browser->submit($crawler->selectButton('Enregistrer')->form(['user[role]' => (string) $this->role(User::ROLE_AGENT)->getId()]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Il doit rester au moins un administrateur actif.');
        $this->em->clear();
        self::assertTrue($this->em->find(User::class, $admin->getId())->isAdmin());
    }

    public function testUnAdminPeutQuitterSonRoleQuandUnAutreAdminExiste(): void
    {
        $other = $this->user(User::ROLE_ADMIN, 'autre@procom.pro');
        $me = $this->user(User::ROLE_ADMIN, 'moi@procom.pro');
        $this->login($me);
        $crawler = $this->browser->request('GET', '/admin/users/'.$other->getId().'/edit');
        $this->browser->submit($crawler->selectButton('Enregistrer')->form(['user[role]' => (string) $this->role(User::ROLE_DIRECTION)->getId()]));

        self::assertResponseRedirects('/admin/users');
        $this->em->clear();
        self::assertSame('Direction', $this->em->find(User::class, $other->getId())->getRoleLabel());
    }

    public function testFiltrerLesUtilisateursParRole(): void
    {
        $role = $this->customRole('Magasinier', [Permission::MATERIEL_VIEW]);
        $this->user($role->getCode(), 'mag1@procom.pro');
        $this->user($role->getCode(), 'mag2@procom.pro');
        $this->user(User::ROLE_AGENT, 'ag@procom.pro');
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/admin/users');
        self::assertCount(4, $crawler->filter('tbody tr'));
        $crawler = $this->browser->request('GET', '/admin/users?role='.$role->getId());
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('.user-filters', '2 utilisateurs');
        $crawler = $this->browser->request('GET', '/admin/users?role=999999');
        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('tbody tr'), 'un rôle inconnu ne filtre rien');
    }

    public function testLeTableauDeBordCompteLesRolesCrees(): void
    {
        $role = $this->customRole('Magasinier');
        $this->user($role->getCode(), 'mag1@procom.pro');
        $this->login($this->user(User::ROLE_ADMIN));

        $this->browser->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.panels', 'Magasinier');
        self::assertSelectorTextContains('.panels', 'Répartition par rôle');
    }

    public function testLesRolesSystemeSontRecreesSiLaBaseEstVide(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM app_role');
        $this->em->clear();
        $manager = static::getContainer()->get(RoleManager::class);

        $manager->ensureSystemRoles();
        $manager->ensureSystemRoles();

        $roles = static::getContainer()->get(RoleRepository::class)->findAllOrdered();
        self::assertSame(['Admin', 'Agent', 'Direction'], array_map(static fn (Role $r): string => $r->getLabel(), $roles));
        self::assertSame([true, true, true], array_map(static fn (Role $r): bool => $r->isSystem(), $roles));
    }

    public function testCommandeDeCreationDUtilisateurAvecUnRoleCree(): void
    {
        $this->customRole('Chef de projet', [Permission::MATERIEL_VIEW]);
        $application = new Application(static::$kernel);
        $command = new CommandTester($application->find('app:create-user'));

        $command->execute(['email' => 'chef@procom.pro', 'password' => 'motdepasse1', '--role' => 'chef_de_projet']);
        self::assertSame(0, $command->getStatusCode(), $command->getDisplay());
        self::assertSame('Chef de projet', $this->em->getRepository(User::class)->findOneBy(['email' => 'chef@procom.pro'])->getRoleLabel());

        $command->execute(['email' => 'ag@procom.pro', 'password' => 'motdepasse1', '--role' => 'ROLE_AGENT']);
        self::assertSame('Agent', $this->em->getRepository(User::class)->findOneBy(['email' => 'ag@procom.pro'])->getRoleLabel());

        $command->execute(['email' => 'zz@procom.pro', 'password' => 'motdepasse1', '--role' => 'inconnu']);
        self::assertSame(2, $command->getStatusCode());
        self::assertStringContainsString('chef_de_projet', $command->getDisplay(), 'les rôles disponibles sont listés');
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'zz@procom.pro']));
    }

    public function testCommandeSuperAdmin(): void
    {
        $application = new Application(static::$kernel);
        $command = new CommandTester($application->find('app:create-superadmin'));

        $command->execute(['email' => 'boss@procom.pro', '--name' => 'Boss', '--password' => 'motdepasse1'], ['interactive' => false]);
        self::assertSame(0, $command->getStatusCode(), $command->getDisplay());
        self::assertTrue($this->em->getRepository(User::class)->findOneBy(['email' => 'boss@procom.pro'])->isAdmin());
    }

    /**
     * Envoie le formulaire de rôle tel que le navigateur le ferait : champs du formulaire et cases « permissions[] » cochées.
     *
     * @param array<string, string> $fields
     * @param list<string>          $permissions
     */
    private function submitRole(string $url, array $fields, array $permissions): void
    {
        $crawler = $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);
        $values = $crawler->filter('form.form')->form()->getPhpValues();
        $values['role'] = $fields + ['description' => ''] + ['_token' => $values['role']['_token']];
        unset($values['permissions']);
        if ($permissions) {
            $values['permissions'] = $permissions;
        }
        $this->browser->request('POST', $url, $values);
    }
}
