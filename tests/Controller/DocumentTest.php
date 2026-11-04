<?php

namespace App\Tests\Controller;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\Encaissement;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Security\Permission;
use App\Service\DocumentNumbering;
use App\Service\EncaissementCalculator;
use App\Service\FiscalSettings;
use App\Tests\AppWebTestCase;

/** Factures et devis : saisie, numérotation, règlement par les encaissements, transformation d'un devis, permissions. */
class DocumentTest extends AppWebTestCase
{
    public function testUnDevisSaisiRecoitLeNumeroAutomatiqueEtSesMontantsCalcules(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $client = $this->client('Société Alpha');

        $crawler = $this->browser->request('GET', '/factures/nouveau?type=devis');
        self::assertResponseIsSuccessful();
        $this->browser->submit($crawler->selectButton('Enregistrer le devis')->form([
            'document[client]' => (string) $client->getId(),
            'document[objet]' => 'Refonte du site',
            'document[montantTtc]' => '118000',
        ]));
        self::assertResponseRedirects();

        $devis = $this->only();
        self::assertSame('DV-'.date('Y').'-001', $devis->getReference());
        self::assertTrue($devis->isDevis());
        self::assertSame('118000.00', $devis->getMontantTtc());
        self::assertSame('100000.00', $devis->getMontantHt());
        self::assertSame('18000.00', $devis->getMontantTva());
        self::assertSame('18.00', $devis->getTauxTva(), 'le taux de TVA est figé à la création');
    }

    public function testLesNumerosSontContinusParSerieEtJamaisReutilises(): void
    {
        $client = $this->client('Alpha');
        $year = date('Y');
        $this->document(Document::TYPE_FACTURE, "FA-$year-001", $client);
        $deleted = $this->document(Document::TYPE_FACTURE, "FA-$year-002", $client);
        $deleted->softDelete(null);
        $this->em->flush();
        $this->document(Document::TYPE_DEVIS, "DV-$year-001", $client);

        $numbering = static::getContainer()->get(DocumentNumbering::class);
        $today = new \DateTimeImmutable('today');
        self::assertSame("FA-$year-003", $numbering->next(Document::TYPE_FACTURE, $today), 'le numéro d\'une facture supprimée n\'est pas repris');
        self::assertSame("DV-$year-002", $numbering->next(Document::TYPE_DEVIS, $today), 'chaque type a sa série');
        self::assertSame('FA-2031-001', $numbering->next(Document::TYPE_FACTURE, new \DateTimeImmutable('2031-03-01')), 'la série repart chaque année');
    }

    public function testUnNumeroDejaUtiliseEstRefuse(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $client = $this->client('Alpha');
        $this->document(Document::TYPE_FACTURE, 'FA-2026-050', $client);

        $crawler = $this->browser->request('GET', '/factures/nouveau');
        $this->browser->submit($crawler->selectButton('Enregistrer la facture')->form([
            'document[reference]' => 'fa-2026-050',
            'document[client]' => (string) $client->getId(),
            'document[objet]' => 'Doublon',
            'document[montantTtc]' => '1000',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Ce numéro est déjà utilisé.');
    }

    public function testLesChampsObligatoiresEtLEcheanceSontControles(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/factures/nouveau');
        $this->browser->submit($crawler->selectButton('Enregistrer la facture')->form([
            'document[objet]' => '',
            'document[montantTtc]' => '',
            'document[dateEmission]' => '2026-05-10',
            'document[dateLimite]' => '2026-05-01',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le client est obligatoire.');
        self::assertSelectorTextContains('body', 'L\'objet est obligatoire.');
        self::assertSelectorTextContains('body', 'Le montant TTC est obligatoire.');
        self::assertSelectorTextContains('body', 'L\'échéance est antérieure à la date d\'émission.');
    }

    public function testLEtatDeReglementSeDeduitDesEncaissementsRattaches(): void
    {
        $facture = $this->document(Document::TYPE_FACTURE, 'FA-2026-001', $this->client('Alpha'), '118000');
        $repository = static::getContainer()->get(DocumentRepository::class);

        self::assertSame('impayee', $facture->settlement(0));
        self::assertSame(118000, $facture->balance(0));

        $this->payment($facture, '40000');
        $paid = $repository->paidFor($facture);
        self::assertSame(40000, $paid);
        self::assertSame('partielle', $facture->settlement($paid));
        self::assertSame(78000, $facture->balance($paid));

        $second = $this->payment($facture, '78000');
        $paid = $repository->paidFor($facture);
        self::assertSame('soldee', $facture->settlement($paid));
        self::assertSame(0, $facture->balance($paid));

        $second->softDelete(null);
        $this->em->flush();
        self::assertSame(40000, $repository->paidFor($facture), 'un encaissement supprimé ne compte plus');
    }

    public function testUneFactureAuDelaDeLEcheanceNonSoldeeEstEnRetard(): void
    {
        $client = $this->client('Alpha');
        $late = $this->document(Document::TYPE_FACTURE, 'FA-2026-001', $client, '100000', Document::EMISE, '-5 days');
        $onTime = $this->document(Document::TYPE_FACTURE, 'FA-2026-002', $client, '100000', Document::EMISE, '+5 days');
        $draft = $this->document(Document::TYPE_FACTURE, 'FA-2026-003', $client, '100000', Document::BROUILLON, '-5 days');

        self::assertTrue($late->isOverdue(0));
        self::assertFalse($late->isOverdue(100000), 'soldée : plus en retard');
        self::assertFalse($onTime->isOverdue(0));
        self::assertFalse($draft->isOverdue(0), 'un brouillon n\'est jamais en retard');
        self::assertNull($draft->settlement(0));

        $this->login($this->user(User::ROLE_ADMIN));
        $crawler = $this->browser->request('GET', '/factures?type=facture&etat=retard');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        self::assertSelectorTextContains('.enc-table tbody', 'FA-2026-001');
        self::assertSelectorTextNotContains('.enc-table tbody', 'FA-2026-002');
    }

    public function testLeTableauDeBordCompteLeResteAEncaisserLesRetardsEtLesDevis(): void
    {
        $client = $this->client('Alpha');
        $late = $this->document(Document::TYPE_FACTURE, 'FA-2026-001', $client, '100000', Document::EMISE, '-5 days');
        $this->payment($late, '30000');
        $this->document(Document::TYPE_FACTURE, 'FA-2026-002', $client, '50000', Document::EMISE, '+10 days');
        $this->document(Document::TYPE_FACTURE, 'FA-2026-003', $client, '999999', Document::BROUILLON, '+10 days');
        $this->document(Document::TYPE_DEVIS, 'DV-2026-001', $client, '200000', Document::ENVOYE, '+10 days');
        $this->document(Document::TYPE_DEVIS, 'DV-2026-002', $client, '10000', Document::ENVOYE, '-1 days');
        $this->document(Document::TYPE_DEVIS, 'DV-2026-003', $client, '10000', Document::ACCEPTE);

        $board = static::getContainer()->get(DocumentRepository::class)->dashboard();

        self::assertSame(70000 + 50000, $board['receivable'], 'les brouillons ne sont pas à encaisser');
        self::assertSame(70000, $board['overdue']);
        self::assertSame(1, $board['overdueCount']);
        self::assertSame(2, $board['openInvoices']);
        self::assertSame(1, $board['quotesPending'], 'un devis expiré n\'est plus en attente');
        self::assertSame(200000, $board['quotesPendingAmount']);
        self::assertSame(1, $board['quotesToInvoice']);
    }

    public function testUnEncaissementSeRattacheALaFactureDeSonClientSeulement(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $alpha = $this->client('Alpha');
        $beta = $this->client('Beta');
        $factureAlpha = $this->document(Document::TYPE_FACTURE, 'FA-2026-001', $alpha, '118000');

        $crawler = $this->browser->request('GET', '/encaissements/nouveau');
        $form = $crawler->selectButton('Enregistrer l\'encaissement')->form();
        $values = [
            'encaissement[dateEncaissement]' => date('Y-m-d'),
            'encaissement[client]' => (string) $beta->getId(),
            'encaissement[facture]' => (string) $factureAlpha->getId(),
            'encaissement[montantTtc]' => '118000',
            'encaissement[montantEspecesMomo]' => '0',
            'encaissement[montantChequeVirement]' => '0',
        ];
        $this->browser->submit($form, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Cette facture est au nom de Alpha, pas du client choisi.');

        $this->browser->submit($crawler->selectButton('Enregistrer l\'encaissement')->form(), ['encaissement[client]' => (string) $alpha->getId()] + $values);
        self::assertResponseRedirects('/encaissements');

        $this->em->clear();
        $payment = $this->em->getRepository(Encaissement::class)->findOneBy([]);
        self::assertSame('FA-2026-001', $payment->getFacture()?->getReference());
    }

    public function testLeBoutonEncaisserPrerempliLeClientEtLeResteDue(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $facture = $this->document(Document::TYPE_FACTURE, 'FA-2026-001', $this->client('Alpha'), '118000');
        $this->payment($facture, '18000');

        $crawler = $this->browser->request('GET', '/encaissements/nouveau?facture='.$facture->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('100000', $crawler->filter('input[name="encaissement[montantTtc]"]')->attr('value'));
        self::assertSame((string) $facture->getId(), $crawler->filter('select[name="encaissement[facture]"] option[selected]')->attr('value'));
    }

    public function testUnDevisAccepteSeTransformeEnFactureUneSeuleFois(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $devis = $this->document(Document::TYPE_DEVIS, 'DV-2026-001', $this->client('Alpha'), '118000', Document::ACCEPTE);

        $crawler = $this->browser->request('GET', '/factures/'.$devis->getId());
        $this->browser->submit($crawler->selectButton('Transformer en facture')->form());
        self::assertResponseRedirects();

        $this->em->clear();
        $facture = $this->em->getRepository(Document::class)->findOneBy(['type' => Document::TYPE_FACTURE]);
        self::assertNotNull($facture);
        self::assertSame('FA-'.date('Y').'-001', $facture->getReference());
        self::assertSame('118000.00', $facture->getMontantTtc());
        self::assertSame('100000.00', $facture->getMontantHt());
        self::assertSame('DV-2026-001', $facture->getSourceDevis()?->getReference());
        self::assertSame(Document::EMISE, $facture->getStatut());

        $crawler = $this->browser->request('GET', '/factures/'.$devis->getId());
        self::assertSelectorNotExists('form[action$="/facturer"]', 'plus de bouton une fois facturé');
        self::assertSelectorTextContains('body', $facture->getReference());
        self::assertCount(1, $this->em->getRepository(Document::class)->findBy(['type' => Document::TYPE_FACTURE]));
    }

    public function testSeulUnDevisAccepteSeTransformeEnFacture(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $devis = $this->document(Document::TYPE_DEVIS, 'DV-2026-001', $this->client('Alpha'), '118000', Document::ENVOYE);

        $crawler = $this->browser->request('GET', '/factures/'.$devis->getId());
        $token = $crawler->filter('form[action$="/statut"] input[name=_token]')->attr('value');
        $this->browser->request('POST', '/factures/'.$devis->getId().'/facturer', ['_token' => $token]);
        self::assertResponseRedirects('/factures/'.$devis->getId());

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Document::class)->findBy(['type' => Document::TYPE_FACTURE]));
    }

    public function testLeStatutSeChangeDepuisLaFicheMaisPasVersUnStatutDUnAutreType(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $devis = $this->document(Document::TYPE_DEVIS, 'DV-2026-001', $this->client('Alpha'), '1000', Document::ENVOYE);

        $crawler = $this->browser->request('GET', '/factures/'.$devis->getId());
        $this->browser->submit($crawler->selectButton('Devis accepté')->form());
        self::assertSame(Document::ACCEPTE, $this->fresh(Document::class, $devis->getId())->getStatut());

        $crawler = $this->browser->request('GET', '/factures/'.$devis->getId());
        $token = $crawler->filter('form[action$="/facturer"] input[name=_token]')->attr('value');
        $this->browser->request('POST', '/factures/'.$devis->getId().'/statut', ['_token' => $token, 'statut' => Document::EMISE]);
        self::assertResponseStatusCodeSame(404, '« émise » n\'existe pas pour un devis');
    }

    public function testUneFactureEncaisseeNePeutNiEtreAnnuleeNiSupprimee(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $facture = $this->document(Document::TYPE_FACTURE, 'FA-2026-001', $this->client('Alpha'), '118000');
        $this->payment($facture, '10000');
        $crawler = $this->browser->request('GET', '/factures/'.$facture->getId());
        $token = $crawler->filter('form[action$="/supprimer"] input[name=_token]')->attr('value');

        $this->browser->request('POST', '/factures/'.$facture->getId().'/statut', ['_token' => $token, 'statut' => Document::ANNULEE]);
        self::assertSame(Document::EMISE, $this->fresh(Document::class, $facture->getId())->getStatut());

        $this->browser->request('POST', '/factures/'.$facture->getId().'/supprimer', ['_token' => $token]);
        self::assertFalse($this->fresh(Document::class, $facture->getId())->isDeleted());
    }

    public function testLaSuppressionEstLogiqueEtLeNumeroResteOccupe(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $facture = $this->document(Document::TYPE_FACTURE, 'FA-2026-001', $this->client('Alpha'));

        $crawler = $this->browser->request('GET', '/factures/'.$facture->getId());
        $this->browser->submit($crawler->filter('form[action$="/supprimer"]')->form());
        self::assertResponseRedirects();

        self::assertTrue($this->fresh(Document::class, $facture->getId())->isDeleted());
        $this->browser->request('GET', '/factures/'.$facture->getId());
        self::assertResponseStatusCodeSame(404);
        $crawler = $this->browser->request('GET', '/factures');
        self::assertSelectorNotExists('.enc-table tbody a[href$="/'.$facture->getId().'"]');
    }

    public function testLesPermissionsGouvernentChaqueAction(): void
    {
        $client = $this->client('Alpha');
        $facture = $this->document(Document::TYPE_FACTURE, 'FA-2026-001', $client);

        // Aucune permission : rien n'est accessible, et l'outil n'apparaît pas à l'accueil.
        $this->login($this->user($this->customRole('Observateur')->getCode(), 'obs@procom.pro'));
        foreach (['/factures', '/factures/'.$facture->getId(), '/factures/nouveau', '/factures/'.$facture->getId().'/modifier'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        // Lecture seule : on consulte, on ne crée ni ne modifie ni ne supprime.
        $this->login($this->user($this->customRole('Lecteur', [Permission::FACTURE_VIEW])->getCode(), 'lecteur@procom.pro'));
        $this->browser->request('GET', '/factures');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href*="/factures/nouveau"]');
        $this->browser->request('GET', '/factures/'.$facture->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/supprimer"]');
        self::assertSelectorNotExists('a[href$="/modifier"]');
        $this->browser->request('GET', '/factures/nouveau');
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('POST', '/factures/'.$facture->getId().'/supprimer');
        self::assertResponseStatusCodeSame(403);

        // L'agent d'origine saisit et modifie, sans pouvoir supprimer.
        $this->login($this->user(User::ROLE_AGENT));
        $this->browser->request('GET', '/factures/nouveau');
        self::assertResponseIsSuccessful();
        $this->browser->request('POST', '/factures/'.$facture->getId().'/supprimer');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLOutilApparaitALAccueilSelonLaPermission(): void
    {
        $this->login($this->user($this->customRole('Lecteur', [Permission::FACTURE_VIEW])->getCode(), 'lecteur@procom.pro'));
        $this->browser->request('GET', '/');
        self::assertSelectorTextContains('.cards', 'Factures et devis');
        self::assertSelectorTextNotContains('.cards', 'Suivi des encaissements');

        $this->login($this->user($this->customRole('Autre', [Permission::MATERIEL_VIEW])->getCode(), 'autre@procom.pro'));
        $this->browser->request('GET', '/');
        self::assertSelectorTextNotContains('.cards', 'Factures et devis');
    }

    public function testLaRechercheEtLesFiltresRestreignentLaListe(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $alpha = $this->client('Alpha');
        $beta = $this->client('Beta');
        $this->document(Document::TYPE_FACTURE, 'FA-2026-001', $alpha, '10000');
        $this->document(Document::TYPE_FACTURE, 'FA-2026-002', $beta, '20000');
        $this->document(Document::TYPE_DEVIS, 'DV-2026-001', $beta, '30000');

        $crawler = $this->browser->request('GET', '/factures');
        self::assertCount(2, $crawler->filter('.enc-table tbody tr'), 'les devis sont dans leur propre onglet');

        $crawler = $this->browser->request('GET', '/factures?type=facture&client='.$beta->getId());
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        self::assertSelectorTextContains('.enc-table tbody', 'FA-2026-002');

        $crawler = $this->browser->request('GET', '/factures?type=facture&q=Alpha');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        self::assertSelectorTextContains('.enc-table tfoot', '10');

        $crawler = $this->browser->request('GET', '/factures?type=devis');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        self::assertSelectorTextContains('.enc-table tbody', 'DV-2026-001');

        $crawler = $this->browser->request('GET', '/factures?etat=nimporte&sort=nimporte&type=nimporte');
        self::assertResponseIsSuccessful('des paramètres invalides retombent sur les valeurs par défaut');
    }

    public function testLeFiltreImpayeeEtSoldeeSeBaseSurLesEncaissements(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $client = $this->client('Alpha');
        $this->document(Document::TYPE_FACTURE, 'FA-2026-001', $client, '100000');
        $partial = $this->document(Document::TYPE_FACTURE, 'FA-2026-002', $client, '100000');
        $this->payment($partial, '40000');
        $done = $this->document(Document::TYPE_FACTURE, 'FA-2026-003', $client, '100000');
        $this->payment($done, '100000');

        foreach (['impayee' => 'FA-2026-001', 'partielle' => 'FA-2026-002', 'soldee' => 'FA-2026-003'] as $etat => $reference) {
            $crawler = $this->browser->request('GET', '/factures?type=facture&etat='.$etat);
            self::assertCount(1, $crawler->filter('.enc-table tbody tr'), $etat);
            self::assertSelectorTextContains('.enc-table tbody', $reference);
        }
    }

    public function testUnEncaissementRattacheApparaitSurLaFicheDeLaFacture(): void
    {
        $this->login($this->user(User::ROLE_ADMIN));
        $facture = $this->document(Document::TYPE_FACTURE, 'FA-2026-001', $this->client('Alpha'), '118000');
        $this->payment($facture, '59000');

        $this->browser->request('GET', '/factures/'.$facture->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Reste à encaisser');
        self::assertSelectorTextContains('body', '59'."\u{00A0}".'000 FCFA');
        self::assertSelectorTextContains('body', 'Payée en partie');

        $this->browser->request('GET', '/encaissements');
        self::assertSelectorTextContains('.enc-table tbody', 'FA-2026-001');
    }

    // ---------- fabriques ----------

    private function client(string $name): Client
    {
        $client = (new Client())->setName($name)->setIfu('1234567890123');
        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    private function document(string $type, string $reference, Client $client, string $ttc = '118000', string $statut = Document::EMISE, string $limit = '+30 days'): Document
    {
        if (Document::TYPE_DEVIS === $type && Document::EMISE === $statut) {
            $statut = Document::ENVOYE;
        }
        $amounts = static::getContainer()->get(EncaissementCalculator::class)->compute($ttc, false, '18.00', '0');
        $document = (new Document($type))->setReference($reference)->setClient($client)->setObjet('Prestation')
            ->setDateEmission(new \DateTimeImmutable('-1 month'))->setDateLimite(new \DateTimeImmutable($limit))->setStatut($statut)
            ->setMontantTtc($ttc)->setMontantHt(EncaissementCalculator::format($amounts->ht))->setMontantTva(EncaissementCalculator::format($amounts->tvaFacturee))->setTauxTva('18.00');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function payment(Document $facture, string $ttc): Encaissement
    {
        $payment = (new Encaissement())->setDateEncaissement(new \DateTimeImmutable('today'))->setClient($facture->getClient())->setFacture($facture)->setMontantTtc($ttc);
        static::getContainer()->get(EncaissementCalculator::class)->apply($payment, static::getContainer()->get(FiscalSettings::class)->get(), true);
        $this->em->persist($payment);
        $this->em->flush();

        return $payment;
    }

    private function only(): Document
    {
        $this->em->clear();

        return $this->em->getRepository(Document::class)->findOneBy([]) ?? self::fail('Aucun document.');
    }
}
