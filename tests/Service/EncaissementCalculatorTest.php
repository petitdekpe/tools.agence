<?php

namespace App\Tests\Service;

use App\Entity\Client;
use App\Entity\Encaissement;
use App\Entity\FiscalSetting;
use App\Service\EncaissementCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EncaissementCalculatorTest extends TestCase
{
    private EncaissementCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new EncaissementCalculator();
    }

    public function testSansRetenueDeTva(): void
    {
        $a = $this->calculator->compute('118000', false, '18.00', '1.00');

        self::assertSame(100000, $a->ht);
        self::assertSame(18000, $a->tvaFacturee);
        self::assertSame(0, $a->tvaPreleveeSource);
        self::assertSame(18000, $a->tvaAReverser);
        self::assertSame(1000, $a->aibPreleveSource);
        self::assertSame(117000, $a->netRecu);
    }

    public function testAvecRetenueDeTva(): void
    {
        $a = $this->calculator->compute('118000', true, '18.00', '1.00');

        self::assertSame(100000, $a->ht);
        self::assertSame(18000, $a->tvaFacturee);
        self::assertSame(18000, $a->tvaPreleveeSource);
        self::assertSame(0, $a->tvaAReverser);
        self::assertSame(1000, $a->aibPreleveSource);
        self::assertSame(99000, $a->netRecu);
    }

    /** 100 000 / 1,18 = 84 745,76… : le HT s'arrondit au franc, la TVA est le complément, l'AIB s'applique au HT arrondi. */
    public function testArrondiSurUnTtcNonDivisible(): void
    {
        $a = $this->calculator->compute('100000', false, '18.00', '1.00');

        self::assertSame(84746, $a->ht);
        self::assertSame(15254, $a->tvaFacturee);
        self::assertSame(15254, $a->tvaAReverser);
        self::assertSame(847, $a->aibPreleveSource); // 847,46 arrondi
        self::assertSame(99153, $a->netRecu);

        $withHolding = $this->calculator->compute('100000', true, '18.00', '1.00');
        self::assertSame(0, $withHolding->tvaAReverser);
        self::assertSame(83899, $withHolding->netRecu); // 100 000 − 15 254 − 847
    }

    public function testArrondiMoitieVersLeHaut(): void
    {
        // HT = 1 050 / 1,18 = 889,83 → 890 ; AIB = 8,90 → 9
        $a = $this->calculator->compute('1050', false, '18.00', '1.00');
        self::assertSame(890, $a->ht);
        self::assertSame(9, $a->aibPreleveSource);

        // HT = 1 050 (TVA 0 %) ; AIB = 10,5 → 11
        $b = $this->calculator->compute('1050', false, '0.00', '1.00');
        self::assertSame(1050, $b->ht);
        self::assertSame(11, $b->aibPreleveSource);
    }

    #[DataProvider('amounts')]
    public function testLesMontantsRestentCoherents(string $ttc, bool $retenue): void
    {
        $a = $this->calculator->compute($ttc, $retenue, '18.00', '1.00');

        self::assertSame((int) $ttc, $a->ht + $a->tvaFacturee);
        self::assertSame($a->tvaFacturee, $a->tvaPreleveeSource + $a->tvaAReverser);
        self::assertSame((int) $ttc - $a->tvaPreleveeSource - $a->aibPreleveSource, $a->netRecu);
        self::assertGreaterThanOrEqual(0, $a->netRecu);
    }

    public static function amounts(): iterable
    {
        foreach (['1', '7', '999', '100000', '118000', '1234567', '9999999999999'] as $ttc) {
            yield "$ttc sans retenue" => [$ttc, false];
            yield "$ttc avec retenue" => [$ttc, true];
        }
    }

    public function testClientAvecIfuAppliqueLeTauxAibNormal(): void
    {
        $e = $this->encaissement('118000', (new Client())->setName('A')->setIfu('3201234567890'));
        $this->calculator->apply($e, $this->settings(), true);

        self::assertSame('1.00', $e->getTauxAib());
        self::assertSame('1000.00', $e->getAibPreleveSource());
        self::assertSame('117000.00', $e->getMontantNetRecu());
    }

    public function testClientSansIfuAppliqueLeTauxAibMajore(): void
    {
        $e = $this->encaissement('118000', (new Client())->setName('B'));
        $this->calculator->apply($e, $this->settings(), true);

        self::assertSame('5.00', $e->getTauxAib());
        self::assertSame('5000.00', $e->getAibPreleveSource());
        self::assertSame('113000.00', $e->getMontantNetRecu());
    }

    public function testUnIfuVideEstTraiteCommeAbsent(): void
    {
        $e = $this->encaissement('118000', (new Client())->setName('C')->setIfu('  '));
        $this->calculator->apply($e, $this->settings(), true);

        self::assertSame('5.00', $e->getTauxAib());
    }

    public function testLesTauxFigesSurviventAuChangementDeParametres(): void
    {
        $e = $this->encaissement('118000', (new Client())->setName('D')->setIfu('123'));
        $this->calculator->apply($e, $this->settings(), true);
        self::assertSame('117000.00', $e->getMontantNetRecu());

        $changed = $this->settings()->setTauxTva('20.00')->setTauxAibAvecIfu('3.00');

        // Modification sans changement de client : les taux figés restent.
        $e->setObservation('modifié');
        $this->calculator->apply($e, $changed, false);
        self::assertSame('18.00', $e->getTauxTva());
        self::assertSame('1.00', $e->getTauxAib());
        self::assertSame('117000.00', $e->getMontantNetRecu());

        // Avec rafraîchissement, les taux actuels sont figés à leur tour.
        $this->calculator->apply($e, $changed, true);
        self::assertSame('20.00', $e->getTauxTva());
        self::assertSame('3.00', $e->getTauxAib());
    }

    public function testLesChampsCalculesEnvoyesParLeClientSontEcrases(): void
    {
        $e = $this->encaissement('118000', (new Client())->setName('E')->setIfu('123'));
        $e->setMontantHt('1.00')->setTvaAReverser('999999.00')->setMontantNetRecu('5.00');
        $this->calculator->apply($e, $this->settings(), true);

        self::assertSame('100000.00', $e->getMontantHt());
        self::assertSame('18000.00', $e->getTvaAReverser());
        self::assertSame('117000.00', $e->getMontantNetRecu());
    }

    public function testTtcInvalideRemetLesChampsCalculesAZero(): void
    {
        $e = $this->encaissement('abc', (new Client())->setName('F'));
        $e->setMontantNetRecu('5.00');
        $this->calculator->apply($e, $this->settings(), true);

        self::assertSame('0.00', $e->getMontantNetRecu());
        self::assertSame('0.00', $e->getMontantHt());
    }

    public function testConversions(): void
    {
        self::assertSame(118000, EncaissementCalculator::toInt('118000.00'));
        self::assertSame(1235, EncaissementCalculator::toInt('1234.50'));
        self::assertSame(0, EncaissementCalculator::toInt(null));
        self::assertSame(0, EncaissementCalculator::toInt('n/a'));
        self::assertSame(1800, EncaissementCalculator::rateToBasisPoints('18.00'));
        self::assertSame(100, EncaissementCalculator::rateToBasisPoints('1'));
        self::assertSame(150, EncaissementCalculator::rateToBasisPoints('1.5'));
        self::assertSame(1, EncaissementCalculator::rateToBasisPoints('0.01'));
    }

    private function encaissement(string $ttc, Client $client, bool $retenue = false): Encaissement
    {
        return (new Encaissement())->setClient($client)->setMontantTtc($ttc)->setRetenueTvaSource($retenue);
    }

    private function settings(): FiscalSetting
    {
        return (new FiscalSetting())->setTauxTva('18.00')->setTauxAibAvecIfu('1.00')->setTauxAibSansIfu('5.00');
    }
}
