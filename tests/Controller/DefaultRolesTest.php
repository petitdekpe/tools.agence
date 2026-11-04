<?php

namespace App\Tests\Controller;

use App\Entity\Role;
use App\Entity\User;
use App\Security\Permission;
use App\Service\PermissionManager;
use App\Service\RoleManager;
use App\Tests\AppWebTestCase;

/** Les rôles d'équipe livrés avec l'application : Administration, RH et finance, Matériel, Stratégie. */
class DefaultRolesTest extends AppWebTestCase
{
    private const TEAMS = [User::ROLE_ADMINISTRATION, User::ROLE_RH_FINANCE, User::ROLE_MATERIEL, User::ROLE_STRATEGIE];

    public function testLesQuatreRolesExistentEtSontDesRolesSysteme(): void
    {
        $labels = [];
        foreach (self::TEAMS as $code) {
            $role = $this->role($code);
            self::assertTrue($role->isSystem(), $code);
            $labels[$code] = $role->getLabel();
        }

        self::assertSame(['Administration', 'RH et finance', 'Matériel', 'Stratégie'], array_values($labels));
        self::assertSame(7, $this->em->getRepository(Role::class)->count([]), 'trois rôles d\'origine et quatre équipes');

        $manager = static::getContainer()->get(RoleManager::class);
        $manager->ensureSystemRoles();
        self::assertSame(7, $this->em->getRepository(Role::class)->count([]), 'recréer les rôles système n\'ajoute rien');
    }

    public function testUnRoleDEquipeNeSeSupprimePas(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('fait partie de l\'application');

        static::getContainer()->get(RoleManager::class)->delete($this->role(User::ROLE_STRATEGIE));
    }

    public function testUnRoleCreeAuMemeNomNePrendPasLeCodeDUnRoleDEquipe(): void
    {
        $manager = static::getContainer()->get(RoleManager::class);

        self::assertSame('ROLE_STRATEGIE_2', $manager->uniqueCode('Stratégie'));
        self::assertSame('ROLE_MATERIEL_2', $manager->uniqueCode('Matériel'));
        self::assertSame('ROLE_RH_ET_FINANCE', $manager->uniqueCode('RH et finance'), 'le code d\'un rôle créé suit son nom : il ne reprend pas ROLE_RH_FINANCE');
    }

    public function testChaqueEquipeADesPermissionsCoherentesAvecSonMetier(): void
    {
        $permissions = static::getContainer()->get(PermissionManager::class);
        $can = fn (string $code, string $permission): bool => $permissions->roleHas($this->role($code), $permission);

        // Administration : espace d'administration en lecture, personnel et congés, pannes ; pas de finances.
        foreach ([Permission::ADMIN_ACCESS, Permission::USERS_VIEW, Permission::ACTIVITY_VIEW, Permission::PERSONNEL_EDIT, Permission::ABSENCE_MANAGE, Permission::TICKET_MANAGE] as $p) {
            self::assertTrue($can(User::ROLE_ADMINISTRATION, $p), "Administration : $p");
        }
        foreach ([Permission::ENCAISSEMENT_VIEW, Permission::DEPENSE_VIEW, Permission::FACTURE_VIEW, Permission::MATERIEL_EDIT, Permission::MISSION_PLAN] as $p) {
            self::assertFalse($can(User::ROLE_ADMINISTRATION, $p), "Administration : pas $p");
        }

        // RH et finance : personnel, congés, encaissements, factures, dépenses ; pas de matériel à modifier ni d'administration.
        foreach ([Permission::PERSONNEL_EDIT, Permission::ABSENCE_MANAGE, Permission::ENCAISSEMENT_EDIT, Permission::ENCAISSEMENT_EXPORT, Permission::FACTURE_EDIT, Permission::DEPENSE_EDIT] as $p) {
            self::assertTrue($can(User::ROLE_RH_FINANCE, $p), "RH et finance : $p");
        }
        foreach ([Permission::MATERIEL_EDIT, Permission::CALENDRIER_EDIT, Permission::MISSION_PLAN, Permission::ADMIN_ACCESS] as $p) {
            self::assertFalse($can(User::ROLE_RH_FINANCE, $p), "RH et finance : pas $p");
        }

        // Matériel : inventaire complet et pannes ; ni finances ni fiches du personnel à modifier.
        foreach ([Permission::MATERIEL_EDIT, Permission::MATERIEL_ASSIGN, Permission::MATERIEL_EXPORT, Permission::TICKET_MANAGE, Permission::PERSONNEL_VIEW] as $p) {
            self::assertTrue($can(User::ROLE_MATERIEL, $p), "Matériel : $p");
        }
        foreach ([Permission::ENCAISSEMENT_VIEW, Permission::DEPENSE_VIEW, Permission::PERSONNEL_EDIT, Permission::ABSENCE_MANAGE] as $p) {
            self::assertFalse($can(User::ROLE_MATERIEL, $p), "Matériel : pas $p");
        }

        // Stratégie : média, projets, programmation des missions, calendrier éditorial ; pas de dépenses ni d'inventaire à modifier.
        foreach ([Permission::CORRECTEUR_USE, Permission::ESPOCRM_USE, Permission::CALENDRIER_EDIT, Permission::CALENDRIER_VALIDATE, Permission::PROJET_EDIT, Permission::MISSION_PLAN, Permission::FACTURE_VIEW] as $p) {
            self::assertTrue($can(User::ROLE_STRATEGIE, $p), "Stratégie : $p");
        }
        foreach ([Permission::DEPENSE_VIEW, Permission::ENCAISSEMENT_VIEW, Permission::MATERIEL_EDIT, Permission::ABSENCE_MANAGE] as $p) {
            self::assertFalse($can(User::ROLE_STRATEGIE, $p), "Stratégie : pas $p");
        }
    }

    public function testToutesLesEquipesOntLeSocleCommunEtAucuneNeSupprime(): void
    {
        $permissions = static::getContainer()->get(PermissionManager::class);
        $base = [Permission::ABSENCE_VIEW, Permission::ABSENCE_CREATE, Permission::TICKET_VIEW, Permission::TICKET_CREATE, Permission::PROJET_VIEW, Permission::CALENDRIER_VIEW];
        $deletions = [Permission::ENCAISSEMENT_DELETE, Permission::FACTURE_DELETE, Permission::DEPENSE_DELETE, Permission::PERSONNEL_DELETE, Permission::MATERIEL_DELETE, Permission::TICKET_DELETE, Permission::ABSENCE_DELETE, Permission::CALENDRIER_DELETE, Permission::PROJET_DELETE];

        foreach (self::TEAMS as $code) {
            $role = $this->role($code);
            foreach ($base as $p) {
                self::assertTrue($permissions->roleHas($role, $p), "$code : $p");
            }
            foreach ($deletions as $p) {
                self::assertFalse($permissions->roleHas($role, $p), "$code : la suppression reste réservée à l'administrateur ($p)");
            }
        }
    }

    public function testLesPermissionsParDefautRespectentLaRegleDAcces(): void
    {
        foreach (self::TEAMS as $code) {
            foreach (Permission::DEFAULTS[$code] as $permission) {
                self::assertTrue(Permission::exists($permission), "$code : $permission est inconnue");
                self::assertContains(Permission::viewOf($permission), Permission::DEFAULTS[$code], "$code : $permission exige l'accès à son outil");
            }
            self::assertSame(array_unique(Permission::DEFAULTS[$code]), Permission::DEFAULTS[$code], "$code : pas de doublon");
        }
    }

    public function testLaStrategieAccedeAuxOutilsMediaEtProgrammationMaisPasAuxFinances(): void
    {
        $this->login($this->user(User::ROLE_STRATEGIE));

        foreach (['/', '/calendrier', '/calendrier/nouvelle', '/projets', '/projets/nouveau', '/missions', '/missions/nouvelle', '/missions/bilan', '/correcteur-media'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        foreach (['/depenses', '/encaissements', '/materiel/nouveau', '/personnel/nouveau', '/admin'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testLesRhEtLaFinanceAccedentAuPersonnelAuxCongesEtALaComptabilite(): void
    {
        $this->login($this->user(User::ROLE_RH_FINANCE));

        foreach (['/personnel/nouveau', '/absences', '/encaissements/nouveau', '/factures/nouveau', '/depenses/nouvelle', '/depenses/synthese'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        foreach (['/materiel/nouveau', '/missions/nouvelle', '/missions/bilan', '/calendrier/nouvelle', '/admin', '/admin/fiscal'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testLEquipeMaterielGereLInventaireEtLesPannes(): void
    {
        $this->login($this->user(User::ROLE_MATERIEL));

        foreach (['/materiel', '/materiel/nouveau', '/pannes', '/personnel'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        foreach (['/personnel/nouveau', '/depenses', '/encaissements', '/missions/nouvelle', '/admin'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testLAdministrationVoitLEspaceAdminEnLectureSeule(): void
    {
        $this->login($this->user(User::ROLE_ADMINISTRATION));

        foreach (['/admin', '/admin/users', '/admin/activity', '/personnel/nouveau', '/absences'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        foreach (['/admin/roles', '/admin/users/new', '/admin/settings', '/admin/fiscal', '/depenses', '/encaissements'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testLesRolesApparaissentDansLAdministrationDesRoles(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/admin/roles');
        self::assertResponseIsSuccessful();
        foreach (['Administration', 'RH et finance', 'Matériel', 'Stratégie'] as $label) {
            self::assertSelectorTextContains('tbody', $label);
        }
        self::assertCount(7, $crawler->filter('tbody tr'));
        $row = $crawler->filter('tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'Stratégie'));
        self::assertCount(0, $row->filter('form[action$="/supprimer"]'), 'pas de bouton de suppression sur un rôle système');
    }
}
