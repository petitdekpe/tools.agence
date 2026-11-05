<?php

namespace App\Tests\Controller;

use App\Entity\ActivityLog;
use App\Entity\LinkTool;
use App\Entity\User;
use App\Tests\AppWebTestCase;

/** Outils de type lien : gestion dans les réglages du site, visibilité par rôle et passerelle d'ouverture. */
class LinkToolTest extends AppWebTestCase
{
    public function testLAdminAjouteUnLien(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $this->submitLink('/admin/settings/liens/nouveau', [
            'title' => 'Drive partagé',
            'url' => 'https://drive.example.com/procom',
            'description' => 'Les fichiers de l\'équipe',
            'category' => 'strategie',
            'icon' => 'folder-not-listed',
        ], [User::ROLE_AGENT]);
        self::assertResponseStatusCodeSame(422, 'une icône hors liste est refusée');

        $this->submitLink('/admin/settings/liens/nouveau', [
            'title' => 'Drive partagé',
            'url' => 'https://drive.example.com/procom',
            'description' => 'Les fichiers de l\'équipe',
            'category' => 'strategie',
            'icon' => 'chart',
        ], [User::ROLE_AGENT]);
        self::assertResponseRedirects('/admin/settings');

        $link = $this->em->getRepository(LinkTool::class)->findOneBy(['title' => 'Drive partagé']);
        self::assertNotNull($link);
        self::assertSame([User::ROLE_AGENT], $link->getRoleCodes());
        self::assertTrue($link->isActive());
        self::assertSame(0, $link->getPosition());
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'lien.create']));

        $this->browser->followRedirect();
        self::assertSelectorTextContains('#liens', 'Drive partagé');
    }

    public function testSeulesLesAdressesHttpSontAcceptees(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));

        foreach (['javascript:alert(1)', 'ftp://exemple.com/x', 'pas une adresse'] as $url) {
            $this->submitLink('/admin/settings/liens/nouveau', ['title' => 'Piège', 'url' => $url, 'category' => 'autres', 'icon' => 'link'], []);
            self::assertResponseStatusCodeSame(422, $url);
        }
        self::assertSame(0, $this->em->getRepository(LinkTool::class)->count([]));
    }

    public function testChaqueRoleNeVoitQueSesLiens(): void
    {
        $this->link('Drive', [User::ROLE_AGENT]);
        $this->link('Compta externe', [User::ROLE_RH_FINANCE]);
        $this->link('Ancien outil', [User::ROLE_AGENT], false);
        $this->link('Sans rôle', []);

        $this->login($this->user(User::ROLE_AGENT));
        $this->browser->request('GET', '/');
        self::assertSelectorTextContains('.rows.cards', 'Drive');
        self::assertSelectorTextNotContains('.rows.cards', 'Compta externe');
        self::assertSelectorTextNotContains('.rows.cards', 'Ancien outil');
        self::assertSelectorTextNotContains('.rows.cards', 'Sans rôle');
        self::assertSelectorTextContains('.nav', 'Drive');

        // L'admin voit tous les liens actifs, y compris ceux sans rôle.
        $this->login($this->user(User::ROLE_ADMIN));
        $this->browser->request('GET', '/');
        foreach (['Drive', 'Compta externe', 'Sans rôle'] as $title) {
            self::assertSelectorTextContains('.rows.cards', $title);
        }
        self::assertSelectorTextNotContains('.rows.cards', 'Ancien outil');
    }

    public function testLaPasserelleVerifieLesDroitsEtTraceLOuverture(): void
    {
        $allowed = $this->link('Drive', [User::ROLE_AGENT]);
        $other = $this->link('Compta externe', [User::ROLE_RH_FINANCE]);
        $off = $this->link('Ancien outil', [User::ROLE_AGENT], false);

        $this->login($this->user(User::ROLE_AGENT));
        $this->browser->request('GET', '/liens/'.$allowed->getId());
        self::assertResponseRedirects('https://example.com/drive');
        $log = $this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'tool.open']);
        self::assertSame('Drive', $log->getDetails()['tool']);

        $this->browser->request('GET', '/liens/'.$other->getId());
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/liens/'.$off->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testLesReglagesDesLiensSontReservesALAdmin(): void
    {
        $link = $this->link('Drive', [User::ROLE_DIRECTION]);

        $this->login($this->user(User::ROLE_DIRECTION));
        foreach (['/admin/settings/liens/nouveau', '/admin/settings/liens/'.$link->getId().'/modifier'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->browser->request('POST', '/admin/settings/liens/'.$link->getId().'/supprimer');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->em->getRepository(LinkTool::class)->count([]));
    }

    public function testModifierEtSupprimerUnLien(): void
    {
        $link = $this->link('Drive', [User::ROLE_AGENT]);
        $this->login($this->user(User::ROLE_ADMIN));

        $this->submitLink('/admin/settings/liens/'.$link->getId().'/modifier', ['title' => 'Drive d\'équipe', 'url' => 'https://example.com/drive', 'category' => 'autres', 'icon' => 'link'], [User::ROLE_AGENT, User::ROLE_MATERIEL], false);
        self::assertResponseRedirects('/admin/settings');
        $fresh = $this->fresh(LinkTool::class, $link->getId());
        self::assertSame('Drive d\'équipe', $fresh->getTitle());
        self::assertFalse($fresh->isActive());
        self::assertEqualsCanonicalizing([User::ROLE_AGENT, User::ROLE_MATERIEL], $fresh->getRoleCodes());
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'lien.update']));

        $crawler = $this->browser->request('GET', '/admin/settings');
        $this->browser->submit($crawler->filter('form[action$="/'.$link->getId().'/supprimer"]')->form());
        self::assertResponseRedirects('/admin/settings');
        self::assertSame(0, $this->em->getRepository(LinkTool::class)->count([]));
        self::assertNotNull($this->em->getRepository(ActivityLog::class)->findOneBy(['action' => 'lien.delete']));
    }

    public function testMonterEtDescendreUnLien(): void
    {
        $first = $this->link('Premier', []);
        $second = $this->link('Deuxième', []);
        $third = $this->link('Troisième', []);
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/admin/settings');
        $form = $crawler->filter('form[action$="/'.$third->getId().'/deplacer"]')->eq(0)->form(); // « ↑ »
        $this->browser->submit($form);
        self::assertResponseRedirects('/admin/settings');

        $titles = array_map(static fn (LinkTool $l): string => $l->getTitle(), static::getContainer()->get(\App\Repository\LinkToolRepository::class)->findAllOrdered());
        self::assertSame(['Premier', 'Troisième', 'Deuxième'], $titles);
        self::assertNotNull($second);
        self::assertNotNull($first);
    }

    /** @param list<string> $roles */
    private function link(string $title, array $roles, bool $active = true): LinkTool
    {
        $link = (new LinkTool())->setTitle($title)->setUrl('https://example.com/'.strtolower(strtok($title, ' ')))->setRoleCodes($roles)->setActive($active)
            ->setPosition($this->em->getRepository(LinkTool::class)->count([]));
        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    /**
     * Envoie le formulaire d'un lien comme le fait le navigateur.
     *
     * @param array<string, string> $fields
     * @param list<string>          $roles codes des rôles cochés
     */
    private function submitLink(string $url, array $fields, array $roles, bool $active = true): void
    {
        $crawler = $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);
        $values = $crawler->filter('form.form')->form()->getPhpValues();
        $data = $fields + ['description' => '', 'badge' => ''] + ['_token' => $values['link_tool']['_token']];
        $data['roleCodes'] = $roles;
        if ($active) {
            $data['active'] = '1';
        }
        $this->browser->request('POST', $url, ['link_tool' => $data]);
    }
}
