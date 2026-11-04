<?php

namespace App\Tests\Controller;

use App\Entity\Materiel;
use App\Entity\Ticket;
use App\Entity\User;
use App\Security\Permission;
use App\Tests\AppWebTestCase;

/** Pannes et demandes de matériel : signalement, numérotation, traitement, lien avec l'état de l'inventaire, permissions. */
class TicketTest extends AppWebTestCase
{
    public function testUnePanneSignaleeRecoitUnNumeroEtMarqueLEquipementEnPanne(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $item = $this->materiel('MacBook Pro 14', 'MB-01');

        $crawler = $this->browser->request('GET', '/pannes/nouveau?type=panne&materiel='.$item->getId());
        self::assertResponseIsSuccessful();
        self::assertSame((string) $item->getId(), $crawler->filter('select[name="ticket[materiel]"] option[selected]')->attr('value'), 'l\'équipement est prérempli');
        $this->browser->submit($crawler->selectButton('Envoyer le signalement')->form([
            'ticket[titre]' => 'L\'écran ne s\'allume plus',
            'ticket[priorite]' => 'haute',
        ]));
        self::assertResponseRedirects();

        $ticket = $this->only();
        self::assertSame('PN-'.date('Y').'-001', $ticket->getReference());
        self::assertTrue($ticket->isPanne());
        self::assertSame(Ticket::OUVERT, $ticket->getStatut());
        self::assertSame('agent@procom.pro', $ticket->getCreatedBy()?->getEmail());
        self::assertSame('panne', $this->fresh(Materiel::class, $item->getId())->getEtat(), 'l\'inventaire passe l\'équipement en panne');
    }

    public function testOnPeutSignalerSansChangerLEtatDeLEquipement(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $item = $this->materiel('Imprimante');

        $crawler = $this->browser->request('GET', '/pannes/nouveau?type=panne');
        $form = $crawler->selectButton('Envoyer le signalement')->form(['ticket[titre]' => 'Bourrage papier', 'ticket[materiel]' => (string) $item->getId()]);
        $form['ticket[marquerEnPanne]']->untick();
        $this->browser->submit($form);
        self::assertResponseRedirects();

        self::assertSame('bon', $this->fresh(Materiel::class, $item->getId())->getEtat());
    }

    public function testUnePanneExigeUnEquipementEtUnTitre(): void
    {
        $this->login($this->user(User::ROLE_AGENT));

        $crawler = $this->browser->request('GET', '/pannes/nouveau?type=panne');
        $this->browser->submit($crawler->selectButton('Envoyer le signalement')->form(['ticket[titre]' => '']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le titre est obligatoire.');
        self::assertSelectorTextContains('body', 'Choisissez l\'équipement en panne.');
    }

    public function testUneDemandeSeFaitSansEquipementEtSaSerieEstDistincte(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $item = $this->materiel();
        $this->ticket(Ticket::TYPE_PANNE, 'PN-'.date('Y').'-001', $item);

        $crawler = $this->browser->request('GET', '/pannes/nouveau?type=demande');
        $this->browser->submit($crawler->selectButton('Envoyer la demande')->form([
            'ticket[titre]' => 'Un second écran',
            'ticket[categorie]' => 'informatique',
        ]));
        self::assertResponseRedirects();

        $this->em->clear();
        $demande = $this->em->getRepository(Ticket::class)->findOneBy(['type' => Ticket::TYPE_DEMANDE]);
        self::assertSame('DM-'.date('Y').'-001', $demande->getReference());
        self::assertNull($demande->getMateriel());
        self::assertSame('Informatique', $demande->getCategorieLabel());
    }

    public function testLaDirectionTraiteUnTicketEtRemetLEquipementEnBonEtat(): void
    {
        $item = $this->materiel('Écran', null, 'panne');
        $ticket = $this->ticket(Ticket::TYPE_PANNE, 'PN-2026-001', $item);

        $this->login($this->user(User::ROLE_DIRECTION));
        $crawler = $this->browser->request('GET', '/pannes/'.$ticket->getId());
        $form = $crawler->selectButton('Mettre à jour')->form(['statut' => Ticket::RESOLU, 'resolution' => 'Câble d\'alimentation remplacé.']);
        $form['remettre_en_etat']->tick();
        $this->browser->submit($form);
        self::assertResponseRedirects('/pannes/'.$ticket->getId());

        $ticket = $this->fresh(Ticket::class, $ticket->getId());
        self::assertSame(Ticket::RESOLU, $ticket->getStatut());
        self::assertNotNull($ticket->getClosedAt());
        self::assertSame('direction@procom.pro', $ticket->getHandledBy()?->getEmail());
        self::assertSame('Câble d\'alimentation remplacé.', $ticket->getResolution());
        self::assertSame('bon', $this->fresh(Materiel::class, $item->getId())->getEtat());
    }

    public function testLEquipementResteEnPanneTantQuUneAutrePanneEstOuverte(): void
    {
        $item = $this->materiel('Écran', null, 'panne');
        $first = $this->ticket(Ticket::TYPE_PANNE, 'PN-2026-001', $item);
        $this->ticket(Ticket::TYPE_PANNE, 'PN-2026-002', $item);

        $this->login($this->user(User::ROLE_DIRECTION));
        $crawler = $this->browser->request('GET', '/pannes/'.$first->getId());
        $form = $crawler->selectButton('Mettre à jour')->form(['statut' => Ticket::RESOLU]);
        $form['remettre_en_etat']->tick();
        $this->browser->submit($form);

        self::assertSame('panne', $this->fresh(Materiel::class, $item->getId())->getEtat());
    }

    public function testUnRejetExigeUneRaison(): void
    {
        $ticket = $this->ticket(Ticket::TYPE_DEMANDE, 'DM-2026-001');
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/pannes/'.$ticket->getId());
        $this->browser->submit($crawler->selectButton('Mettre à jour')->form(['statut' => Ticket::REJETE, 'resolution' => '']));
        self::assertSame(Ticket::OUVERT, $this->fresh(Ticket::class, $ticket->getId())->getStatut(), 'rien n\'est enregistré');

        $crawler = $this->browser->request('GET', '/pannes/'.$ticket->getId());
        self::assertSelectorTextContains('body', 'Indiquez la raison du rejet.');
        $this->browser->submit($crawler->selectButton('Mettre à jour')->form(['statut' => Ticket::REJETE, 'resolution' => 'Budget épuisé.']));
        self::assertSame(Ticket::REJETE, $this->fresh(Ticket::class, $ticket->getId())->getStatut());
    }

    public function testLAuteurModifieSonTicketTantQuIlEstOuvertSeulement(): void
    {
        $this->login($this->user(User::ROLE_AGENT));
        $crawler = $this->browser->request('GET', '/pannes/nouveau?type=demande');
        $this->browser->submit($crawler->selectButton('Envoyer la demande')->form(['ticket[titre]' => 'Souris']));
        $ticket = $this->only();

        $this->browser->request('GET', '/pannes/'.$ticket->getId().'/modifier');
        self::assertResponseIsSuccessful();

        $ticket->transition(Ticket::EN_COURS, null);
        $this->em->flush();
        $this->browser->request('GET', '/pannes/'.$ticket->getId().'/modifier');
        self::assertResponseStatusCodeSame(403, 'pris en charge : plus modifiable par son auteur');

        $other = $this->user(User::ROLE_AGENT, 'autre@procom.pro');
        $open = $this->ticket(Ticket::TYPE_DEMANDE, 'DM-2026-009');
        $this->login($other);
        $this->browser->request('GET', '/pannes/'.$open->getId().'/modifier');
        self::assertResponseStatusCodeSame(403, 'pas le ticket d\'un autre');
    }

    public function testLesPermissionsGouvernentChaqueAction(): void
    {
        $ticket = $this->ticket(Ticket::TYPE_PANNE, 'PN-2026-001', $this->materiel());

        $this->login($this->user($this->customRole('Observateur')->getCode(), 'obs@procom.pro'));
        foreach (['/pannes', '/pannes/'.$ticket->getId(), '/pannes/nouveau'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->login($this->user($this->customRole('Lecteur', [Permission::TICKET_VIEW])->getCode(), 'lecteur@procom.pro'));
        $this->browser->request('GET', '/pannes');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href*="/pannes/nouveau"]');
        $this->browser->request('GET', '/pannes/nouveau');
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->browser->request('GET', '/pannes/'.$ticket->getId());
        self::assertSelectorNotExists('form[action$="/statut"]');
        self::assertSelectorNotExists('form[action$="/supprimer"]');

        // L'agent d'origine signale mais ne traite pas ; seul l'administrateur supprime.
        $this->login($this->user(User::ROLE_AGENT));
        $crawler = $this->browser->request('GET', '/pannes/'.$ticket->getId());
        self::assertSelectorNotExists('form[action$="/statut"]');
        $this->browser->request('POST', '/pannes/'.$ticket->getId().'/statut', ['statut' => Ticket::RESOLU]);
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('POST', '/pannes/'.$ticket->getId().'/supprimer');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLaSuppressionEstLogique(): void
    {
        $ticket = $this->ticket(Ticket::TYPE_PANNE, 'PN-2026-001', $this->materiel());
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/pannes/'.$ticket->getId());
        $this->browser->submit($crawler->filter('form[action$="/supprimer"]')->form());
        self::assertResponseRedirects();

        self::assertTrue($this->fresh(Ticket::class, $ticket->getId())->isDeleted());
        $this->browser->request('GET', '/pannes/'.$ticket->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testLaListeSeFiltreEtSeTrieParGravite(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $item = $this->materiel('Écran 27 pouces');
        $this->ticket(Ticket::TYPE_PANNE, 'PN-2026-001', $item, 'basse');
        $urgent = $this->ticket(Ticket::TYPE_PANNE, 'PN-2026-002', $item, 'urgente');
        $closed = $this->ticket(Ticket::TYPE_PANNE, 'PN-2026-003', $item, 'normale');
        $closed->transition(Ticket::RESOLU, null);
        $this->ticket(Ticket::TYPE_DEMANDE, 'DM-2026-001');
        $this->em->flush();

        $crawler = $this->browser->request('GET', '/pannes');
        self::assertCount(3, $crawler->filter('.enc-table tbody tr'), 'les demandes sont dans leur onglet');

        $crawler = $this->browser->request('GET', '/pannes?type=panne&etat=actifs');
        self::assertCount(2, $crawler->filter('.enc-table tbody tr'));

        $crawler = $this->browser->request('GET', '/pannes?type=panne&sort=priorite&dir=desc');
        self::assertSame('PN-2026-002', trim($crawler->filter('.enc-table tbody tr')->first()->filter('td')->first()->text()), 'l\'urgente passe en tête');

        $crawler = $this->browser->request('GET', '/pannes?type=panne&q=27+pouces&priorite=urgente');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));

        $this->browser->request('GET', '/pannes?etat=nimporte&priorite=zzz&sort=zzz&type=zzz');
        self::assertResponseIsSuccessful('des paramètres invalides retombent sur les valeurs par défaut');
    }

    public function testLaFicheDUnEquipementProposeDeSignalerUnePanne(): void
    {
        $item = $this->materiel();
        $this->login($this->user(User::ROLE_AGENT));
        $this->browser->request('GET', '/materiel/'.$item->getId());
        self::assertSelectorExists('a[href*="/pannes/nouveau"][href*="materiel='.$item->getId().'"]');

        $this->login($this->user($this->customRole('Magasinier', [Permission::MATERIEL_VIEW])->getCode(), 'mag@procom.pro'));
        $this->browser->request('GET', '/materiel/'.$item->getId());
        self::assertSelectorNotExists('a[href*="/pannes/nouveau"]');
    }

    // ---------- fabriques ----------

    private function ticket(string $type, string $reference, ?Materiel $materiel = null, string $priorite = 'normale'): Ticket
    {
        $ticket = (new Ticket($type))->setReference($reference)->setTitre('Problème '.$reference)->setMateriel($materiel)->setPriorite($priorite);
        $this->em->persist($ticket);
        $this->em->flush();

        return $ticket;
    }

    private function only(): Ticket
    {
        $this->em->clear();

        return $this->em->getRepository(Ticket::class)->findOneBy([]) ?? self::fail('Aucun ticket.');
    }
}
