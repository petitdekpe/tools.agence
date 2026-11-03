<?php

namespace App\Dto;

/** Montants calculés d'un encaissement, en francs CFA entiers. */
final readonly class EncaissementAmounts
{
    public function __construct(
        public int $ht,
        public int $tvaFacturee,
        public int $tvaPreleveeSource,
        public int $tvaAReverser,
        public int $aibPreleveSource,
        public int $netRecu,
    ) {
    }
}
