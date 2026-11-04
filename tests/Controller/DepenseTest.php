<?php

namespace App\Tests\Controller;

use App\Entity\Client;
use App\Entity\Depense;
use App\Entity\Encaissement;
use App\Entity\User;
use App\Repository\DepenseRepository;
use App\Security\Permission;
use App\Service\EncaissementCalculator;
use App\Service\FiscalSettings;
use App\Tests\AppWebTestCase;

/** Dépenses et décaissements : saisie, TVA déductible, paiement, retards, synthèse face aux encaissements, permissions. */
class DepenseTest extends AppWebTestCase
{
    public function testUneDepenseAvecTvaCalculeSonHtEtSaTvaDeductible(): void
    {
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/depenses/nouvelle');
        self::assertResponseIsSuccessful();
        $this->browser->submit($crawler->selectButton('Enregistrer la dépense')->form([
            'depense[fournisseur]' => 'Studio Lumière',
            'depense[categorie]' => 'soustraitance',
            'depense[libelle]' => 'Location du studio',
            'depense[numeroPiece]' => 'F-2026-77',
            'depense[montantTtc]' => '118000',
            'depense[echeance]' => date('Y-m-d', strtotime('+15 days')),
        ]));
        self::assertResponseRedirects('/depenses');

        $d = $this->only();
        self::assertSame('100000.00', $d->getMontantHt());
        self::assertSame('18000.00', $d->getMontantTva());
        self::assertSame('18.00', $d->getTauxTva(), 'le taux est figé à la création');
        self::assertFalse($d->isPaid());
        self::assertSame('direction@procom.pro', $d->getCreatedBy()?->getEmail());
    }

    public function testUnFournisseurNonAssujettiNeDonnePasDeTvaDeductible(): void
    {
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/depenses/nouvelle');
        $form = $crawler->selectButton('Enregistrer la dépense')->form([
            'depense[fournisseur]' => 'Petit commerce',
            'depense[libelle]' => 'Papeterie',
            'depense[montantTtc]' => '25000',
        ]);
        $form['depense[tvaApplicable]']->untick();
        $this->browser->submit($form);
        self::assertResponseRedirects('/depenses');

        $d = $this->only();
        self::assertSame('25000.00', $d->getMontantHt());
        self::assertSame('0.00', $d->getMontantTva());
    }

    public function testLesChampsObligatoiresEtLeModeDePaiementSontControles(): void
    {
        $this->login($this->user(User::ROLE_DIRECTION));

        $crawler = $this->browser->request('GET', '/depenses/nouvelle');
        $this->browser->submit($crawler->selectButton('Enregistrer la dépense')->form(['depense[fournisseur]' => '', 'depense[libelle]' => '', 'depense[montantTtc]' => '']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le fournisseur est obligatoire.');
        self::assertSelectorTextContains('body', 'Le libellé est obligatoire.');
        self::assertSelectorTextContains('body', 'Le montant TTC est obligatoire.');

        $crawler = $this->browser->request('GET', '/depenses/nouvelle');
        $this->browser->submit($crawler->selectButton('Enregistrer la dépense')->form([
            'depense[fournisseur]' => 'X', 'depense[libelle]' => 'Y', 'depense[montantTtc]' => '1000',
            'depense[datePaiement]' => date('Y-m-d'),
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Indiquez le mode de paiement.');

        $crawler = $this->browser->request('GET', '/depenses/nouvelle');
        $this->browser->submit($crawler->selectButton('Enregistrer la dépense')->form([
            'depense[fournisseur]' => 'X', 'depense[libelle]' => 'Y', 'depense[montantTtc]' => '1000',
            'depense[echeance]' => date('Y-m-d', strtotime('-5 days')), 'depense[dateDepense]' => date('Y-m-d'),
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'L\'échéance est antérieure à la date de la dépense.');
    }

    public function testLeBoutonPayerPrerempliLaDateDuJourEtLeDecaissementSEnregistre(): void
    {
        $this->login($this->user(User::ROLE_DIRECTION));
        $d = $this->depense('Studio', '118000', '-10 days', '+5 days');

        $crawler = $this->browser->request('GET', '/depenses/'.$d->getId().'/modifier?payer=1');
        self::assertSame(date('Y-m-d'), $crawler->filter('input[name="depense[datePaiement]"]')->attr('value'));
        $this->browser->submit($crawler->selectButton('Enregistrer les modifications')->form(['depense[mode]' => 'virement']));
        self::assertResponseRedirects('/depenses');

        $d = $this->fresh(Depense::class, $d->getId());
        self::assertTrue($d->isPaid());
        self::assertSame('Virement', $d->getModeLabel());
    }

    public function testLEtatSeDeduitDeLEcheanceEtDuPaiement(): void
    {
        $paid = $this->depense('A', '1000', '-10 days', '-5 days', '-6 days');
        $late = $this->depense('B', '2000', '-10 days', '-2 days');
        $open = $this->depense('C', '3000', '-1 day', '+9 days');
        $noDue = $this->depense('D', '4000', '-1 day', null);

        self::assertTrue($paid->isPaid());
        self::assertFalse($paid->isOverdue(), 'payée : plus en retard');
        self::assertTrue($late->isOverdue());
        self::assertFalse($open->isOverdue());
        self::assertFalse($noDue->isOverdue(), 'sans échéance : jamais en retard');

        $board = static::getContainer()->get(DepenseRepository::class)->dashboard();
        self::assertSame(2000 + 3000 + 4000, $board['unpaid']);
        self::assertSame(3, $board['unpaidCount']);
        self::assertSame(2000, $board['overdue']);
        self::assertSame(1, $board['overdueCount']);
    }

    public function testLaListeSeFiltreEtLesTotauxSuiventLesFiltres(): void
    {
        $this->login($this->user(User::ROLE_DIRECTION));
        $this->depense('Studio Lumière', '118000', '-10 days', '+5 days', null, 'soustraitance');
        $this->depense('Papeterie Centrale', '10000', '-9 days', null, '-8 days', 'fournitures', 'especes');
        $this->depense('Studio Lumière', '50000', '-2 days', '-1 day', null, 'soustraitance');

        $crawler = $this->browser->request('GET', '/depenses');
        self::assertCount(3, $crawler->filter('.enc-table tbody tr'));
        self::assertSelectorTextContains('.enc-table tfoot', '178');

        $crawler = $this->browser->request('GET', '/depenses?etat=payee');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/depenses?etat=apayer');
        self::assertCount(2, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/depenses?etat=retard');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));
        $crawler = $this->browser->request('GET', '/depenses?q=studio');
        self::assertCount(2, $crawler->filter('.enc-table tbody tr'));
        self::assertSelectorTextContains('.enc-table tfoot', '168');
        $crawler = $this->browser->request('GET', '/depenses?categorie=fournitures&mode=especes');
        self::assertCount(1, $crawler->filter('.enc-table tbody tr'));

        $this->browser->request('GET', '/depenses?etat=zzz&categorie=zzz&mode=zzz&sort=zzz&page=abc');
        self::assertResponseIsSuccessful('des paramètres invalides retombent sur les valeurs par défaut');
    }

    public function testLesFournisseursDejaSaisisSontSuggeres(): void
    {
        $this->login($this->user(User::ROLE_DIRECTION));
        $this->depense('Studio Lumière', '1000', '-1 day', null);

        $crawler = $this->browser->request('GET', '/depenses/nouvelle');
        self::assertSame('Studio Lumière', $crawler->filter('datalist#fournisseurs option')->attr('value'));
    }

    public function testLaSyntheseMetLeDecaisseFaceAuxEncaissementsEtLaTvaNette(): void
    {
        $this->login($this->user(User::ROLE_DIRECTION));
        $client = (new Client())->setName('Alpha')->setIfu('1234567890123');
        $this->em->persist($client);
        $payment = (new Encaissement())->setDateEncaissement(new \DateTimeImmutable('today'))->setClient($client)->setMontantTtc('118000');
        static::getContainer()->get(EncaissementCalculator::class)->apply($payment, static::getContainer()->get(FiscalSettings::class)->get(), true);
        $this->em->persist($payment);
        $this->em->flush();
        // 118 000 TTC encaissés : net 117 000 (AIB 1 000), TVA à reverser 18 000.
        $this->depense('Studio', '59000', '0 days', null, '0 days'); // TVA déductible 9 000, payée

        $crawler = $this->browser->request('GET', '/depenses/synthese');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('.enc-table tbody tr')->first()->text();
        $nb = "\u{00A0}";
        self::assertStringContainsString('117'.$nb.'000', $row, 'encaissé net');
        self::assertStringContainsString('59'.$nb.'000', $row, 'décaissé');
        self::assertStringContainsString('58'.$nb.'000', $row, 'solde 117 000 - 59 000');
        self::assertStringContainsString('9'.$nb.'000', $row, 'TVA nette 18 000 - 9 000');
        self::assertSelectorTextContains('body', 'Par catégorie');
        self::assertSelectorTextContains('body', 'Studio');
    }

    public function testSansLaPermissionEncaissementsLaSyntheseNeMontrePasLesRecettes(): void
    {
        $this->login($this->user($this->customRole('Achats', [Permission::DEPENSE_VIEW])->getCode(), 'achats@procom.pro'));
        $this->depense('Studio', '59000', '0 days', null, '0 days');

        $this->browser->request('GET', '/depenses/synthese');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Encaissé (net)');
        self::assertSelectorTextNotContains('body', 'Solde');
        self::assertSelectorTextContains('body', 'TVA déductible');
    }

    public function testLesPermissionsGouvernentChaqueAction(): void
    {
        $d = $this->depense('Studio', '1000', '-1 day', null);

        $this->login($this->user(User::ROLE_AGENT));
        foreach (['/depenses', '/depenses/synthese', '/depenses/nouvelle', '/depenses/'.$d->getId().'/modifier'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->login($this->user($this->customRole('Lecteur', [Permission::DEPENSE_VIEW])->getCode(), 'lecteur@procom.pro'));
        $this->browser->request('GET', '/depenses');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href*="/depenses/nouvelle"]');
        self::assertSelectorNotExists('a[href$="/modifier"]');
        self::assertSelectorNotExists('form[action$="/supprimer"]');
        $this->browser->request('GET', '/depenses/nouvelle');
        self::assertResponseStatusCodeSame(403);

        $this->login($this->user(User::ROLE_DIRECTION));
        $this->browser->request('GET', '/depenses');
        self::assertSelectorExists('a[href$="/modifier"]');
        self::assertSelectorNotExists('form[action$="/supprimer"]', 'la direction saisit mais ne supprime pas');
        $this->browser->request('POST', '/depenses/'.$d->getId().'/supprimer');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLaSuppressionEstLogique(): void
    {
        $d = $this->depense('Studio', '1000', '-1 day', null);
        $this->login($this->user(User::ROLE_ADMIN));

        $crawler = $this->browser->request('GET', '/depenses');
        $this->browser->submit($crawler->filter('form[action$="/supprimer"]')->form());
        self::assertResponseRedirects('/depenses');

        self::assertTrue($this->fresh(Depense::class, $d->getId())->isDeleted());
        $crawler = $this->browser->request('GET', '/depenses');
        self::assertSelectorTextContains('.enc-table tbody', 'Aucune dépense');
        $this->browser->request('GET', '/depenses/'.$d->getId().'/modifier');
        self::assertResponseStatusCodeSame(404);
    }

    // ---------- fabriques ----------

    private function depense(string $fournisseur, string $ttc, string $date, ?string $echeance, ?string $paidOn = null, string $categorie = 'autre', ?string $mode = null): Depense
    {
        $amounts = static::getContainer()->get(EncaissementCalculator::class)->compute($ttc, false, '18.00', '0');
        $d = (new Depense())->setDateDepense(new \DateTimeImmutable($date))->setFournisseur($fournisseur)->setCategorie($categorie)->setLibelle('Prestation')
            ->setMontantTtc($ttc)->setMontantHt(EncaissementCalculator::format($amounts->ht))->setMontantTva(EncaissementCalculator::format($amounts->tvaFacturee))->setTauxTva('18.00')
            ->setEcheance($echeance ? new \DateTimeImmutable($echeance) : null);
        if ($paidOn) {
            $d->setDatePaiement(new \DateTimeImmutable($paidOn))->setMode($mode ?? 'virement');
        }
        $this->em->persist($d);
        $this->em->flush();

        return $d;
    }

    private function only(): Depense
    {
        $this->em->clear();

        return $this->em->getRepository(Depense::class)->findOneBy([]) ?? self::fail('Aucune dépense.');
    }
}
