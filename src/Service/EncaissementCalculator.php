<?php

namespace App\Service;

use App\Dto\EncaissementAmounts;
use App\Entity\Encaissement;
use App\Entity\FiscalSetting;

/**
 * Seule source de vérité pour les montants d'un encaissement.
 *
 * Tout se calcule en entiers (francs, taux en centièmes de pour cent) avec un arrondi à
 * l'unité « moitié vers le haut », sans flottants. Le contrôleur Stimulus
 * encaissement_form_controller.js reproduit exactement les mêmes étapes pour l'aperçu.
 */
class EncaissementCalculator
{
    /** Taux de 100 % exprimé en centièmes de pour cent. */
    private const FULL = 10000;

    /**
     * @param string $tauxTva taux de TVA en %, ex. « 18.00 »
     * @param string $tauxAib taux d'AIB en %, ex. « 1.00 »
     */
    public function compute(string $montantTtc, bool $retenueTvaSource, string $tauxTva, string $tauxAib): EncaissementAmounts
    {
        $ttc = self::toInt($montantTtc);
        $tva = self::rateToBasisPoints($tauxTva);
        $aib = self::rateToBasisPoints($tauxAib);

        $ht = self::divideRounded($ttc * self::FULL, self::FULL + $tva);
        $tvaFacturee = $ttc - $ht;
        $tvaPrelevee = $retenueTvaSource ? $tvaFacturee : 0;
        $aibPrelevee = self::divideRounded($ht * $aib, self::FULL);

        return new EncaissementAmounts(
            ht: $ht,
            tvaFacturee: $tvaFacturee,
            tvaPreleveeSource: $tvaPrelevee,
            tvaAReverser: $tvaFacturee - $tvaPrelevee,
            aibPreleveSource: $aibPrelevee,
            netRecu: $ttc - $tvaPrelevee - $aibPrelevee,
        );
    }

    /**
     * Recalcule les champs calculés de l'encaissement, quoi qu'il contienne déjà.
     *
     * @param bool $refreshRates true pour figer les taux actuels (création, changement de client),
     *                           false pour garder ceux déjà figés sur l'enregistrement
     */
    public function apply(Encaissement $encaissement, FiscalSetting $settings, bool $refreshRates): void
    {
        if ($refreshRates) {
            $encaissement->setTauxTva($settings->getTauxTva());
            $encaissement->setTauxAib($settings->aibRateFor($encaissement->getClient()));
        }

        $ttc = $encaissement->getMontantTtc();
        if (null === $ttc || !is_numeric($ttc) || self::toInt($ttc) <= 0) {
            $amounts = new EncaissementAmounts(0, 0, 0, 0, 0, 0);
        } else {
            $amounts = $this->compute($ttc, $encaissement->isRetenueTvaSource(), $encaissement->getTauxTva(), $encaissement->getTauxAib());
        }

        $encaissement
            ->setMontantHt(self::format($amounts->ht))
            ->setTvaFacturee(self::format($amounts->tvaFacturee))
            ->setTvaPreleveeSource(self::format($amounts->tvaPreleveeSource))
            ->setTvaAReverser(self::format($amounts->tvaAReverser))
            ->setAibPreleveSource(self::format($amounts->aibPreleveSource))
            ->setMontantNetRecu(self::format($amounts->netRecu));
    }

    /** « 118000.00 » → 118000. Les décimales éventuelles sont arrondies au franc. */
    public static function toInt(?string $amount): int
    {
        if (null === $amount || !preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', trim($amount), $m)) {
            return 0;
        }
        $int = (int) $m[2] + (isset($m[3][0]) && $m[3][0] >= '5' ? 1 : 0);

        return '-' === $m[1] ? -$int : $int;
    }

    /** « 18.00 » → 1800, « 1.5 » → 150. */
    public static function rateToBasisPoints(string $rate): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d*))?$/', trim($rate), $m)) {
            return 0;
        }
        $decimals = $m[2] ?? '';
        $fraction = str_pad(substr($decimals, 0, 2), 2, '0');
        $extra = isset($decimals[2]) && $decimals[2] >= '5' ? 1 : 0;

        return (int) $m[1] * 100 + (int) $fraction + $extra;
    }

    public static function format(int $amount): string
    {
        return $amount.'.00';
    }

    /** Division entière arrondie à l'unité la plus proche (0,5 vers le haut), pour des valeurs positives. */
    private static function divideRounded(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
