<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\Permission;
use App\Tests\AppWebTestCase;

/** Catégories du menu latéral : elles suivent les permissions du rôle. */
class NavigationTest extends AppWebTestCase
{
    /** @return list<string> */
    private function labels(): array
    {
        $this->browser->request('GET', '/');
        self::assertResponseIsSuccessful();

        return $this->browser->getCrawler()->filter('.nav .nav-label')->each(static fn ($node): string => trim($node->text()));
    }

    public function testAdminVoitToutesLesCategoriesDansLOrdre(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));

        self::assertSame(['Administration', 'RH et finance', 'Matériel', 'Stratégie'], $this->labels());
    }

    public function testUneCategorieNApparaitQueSiLeRoleDonneAccesAUnDeSesOutils(): void
    {
        $finance = $this->customRole('Finance', [Permission::ENCAISSEMENT_VIEW, Permission::DEPENSE_VIEW]);
        $this->login($this->user($finance->getCode(), 'finance@procom.pro'));
        self::assertSame(['RH et finance'], $this->labels());
        self::assertSelectorTextContains('.nav-cat-rh_finance', 'Suivi des encaissements');
        self::assertSelectorTextContains('.nav-cat-rh_finance', 'Dépenses et décaissements');

        $media = $this->customRole('Médias', [Permission::CORRECTEUR_USE, Permission::CALENDRIER_VIEW, Permission::PROJET_VIEW]);
        $this->login($this->user($media->getCode(), 'media@procom.pro'));
        self::assertSame(['Stratégie'], $this->labels());
        foreach (['Correcteur média', 'Calendrier éditorial', 'Projets et missions'] as $tool) {
            self::assertSelectorTextContains('.nav-cat-strategie', $tool);
        }

        $parc = $this->customRole('Parc', [Permission::MATERIEL_VIEW, Permission::TICKET_VIEW]);
        $this->login($this->user($parc->getCode(), 'parc@procom.pro'));
        self::assertSame(['Matériel'], $this->labels());
        self::assertSelectorTextContains('.nav-cat-materiel', 'Pannes et demandes');
    }

    public function testLePersonnelEtLesAbsencesReleventDeRhEtFinance(): void
    {
        $rh = $this->customRole('RH', [Permission::PERSONNEL_VIEW, Permission::ABSENCE_VIEW]);
        $this->login($this->user($rh->getCode(), 'rh@procom.pro'));

        self::assertSame(['RH et finance'], $this->labels());
        self::assertSelectorTextContains('.nav-cat-rh_finance', 'Gestion du personnel');
        self::assertSelectorTextContains('.nav-cat-rh_finance', 'Congés et absences');
    }

    public function testUnRoleSansAucunOutilNAPasDeCategorie(): void
    {
        $this->login($this->user($this->customRole('Vide')->getCode(), 'vide@procom.pro'));

        self::assertSame([], $this->labels());
    }
}
