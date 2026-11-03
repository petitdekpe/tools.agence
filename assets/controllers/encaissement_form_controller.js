import { Controller } from '@hotwired/stimulus';

/*
 * Aperçu en direct des montants d'un encaissement.
 *
 * Reproduit à l'identique EncaissementCalculator (PHP) : entiers uniquement (BigInt, taux en
 * centièmes de pour cent), arrondi à l'unité « moitié vers le haut ». Le serveur recalcule tout
 * à l'enregistrement et reste la source de vérité.
 */
const FULL = 10000n;

const toInt = (value) => {
    const m = /^(-?)(\d+)(?:\.(\d*))?$/.exec(String(value ?? '').trim());
    if (!m) {
        return 0n;
    }
    const int = BigInt(m[2]) + (m[3] && m[3][0] >= '5' ? 1n : 0n);

    return m[1] === '-' ? -int : int;
};

const rateToBasisPoints = (rate) => {
    const m = /^(\d+)(?:\.(\d*))?$/.exec(String(rate ?? '').trim());
    if (!m) {
        return 0n;
    }
    const decimals = m[2] ?? '';
    const extra = decimals[2] >= '5' ? 1n : 0n;

    return BigInt(m[1]) * 100n + BigInt(decimals.slice(0, 2).padEnd(2, '0')) + extra;
};

const divideRounded = (numerator, denominator) => (2n * numerator + denominator) / (2n * denominator);

const format = (amount) => amount.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

export default class extends Controller {
    static targets = ['client', 'ttc', 'retenue', 'especes', 'cheque', 'ht', 'tvaFacturee', 'tvaSource', 'tvaReverser', 'aib', 'net', 'rates', 'warning'];
    static values = {
        tva: String,
        aibAvecIfu: String,
        aibSansIfu: String,
        frozenTva: String,
        frozenAib: String,
        frozenClient: String,
    };

    connect() {
        this.recalculate();
    }

    recalculate() {
        const { tva, aib } = this.rates();
        const ttc = toInt(this.ttcTarget.value);
        let result = { ht: 0n, tvaFacturee: 0n, tvaSource: 0n, tvaReverser: 0n, aib: 0n, net: 0n };

        if (ttc > 0n) {
            const ht = divideRounded(ttc * FULL, FULL + rateToBasisPoints(tva));
            const tvaFacturee = ttc - ht;
            const tvaSource = this.retenueTarget.checked ? tvaFacturee : 0n;
            const aibAmount = divideRounded(ht * rateToBasisPoints(aib), FULL);
            result = { ht, tvaFacturee, tvaSource, tvaReverser: tvaFacturee - tvaSource, aib: aibAmount, net: ttc - tvaSource - aibAmount };
        }

        this.net = result.net;
        for (const key of ['ht', 'tvaFacturee', 'tvaSource', 'tvaReverser', 'aib', 'net']) {
            this[`${key}Target`].textContent = `${format(result[key])} FCFA`;
        }
        this.ratesTarget.textContent = `TVA ${tva} % · AIB ${aib} %`;
        this.checkRepartition();
    }

    allCash() {
        this.especesTarget.value = this.net.toString();
        this.chequeTarget.value = '0';
        this.checkRepartition();
    }

    allTransfer() {
        this.chequeTarget.value = this.net.toString();
        this.especesTarget.value = '0';
        this.checkRepartition();
    }

    checkRepartition() {
        const ecart = (this.net ?? 0n) - toInt(this.especesTarget.value) - toInt(this.chequeTarget.value);
        const box = this.warningTarget;
        box.classList.remove('ok', 'warn', 'bad');

        if (this.net === 0n && toInt(this.ttcTarget.value) <= 0n) {
            box.hidden = true;

            return;
        }
        box.hidden = false;
        if (ecart === 0n) {
            box.classList.add('ok');
            box.textContent = 'Répartition cohérente avec le net reçu.';
        } else if (ecart > 0n) {
            box.classList.add('warn');
            box.textContent = `Écart de ${format(ecart)} FCFA : il reste ce montant à répartir.`;
        } else {
            box.classList.add('bad');
            box.textContent = `La répartition dépasse le net reçu de ${format(-ecart)} FCFA : l'enregistrement sera refusé.`;
        }
    }

    /** Mêmes règles que le serveur : taux figés à la modification, sauf si le client change. */
    rates() {
        const option = this.clientTarget.selectedOptions[0];
        const hasIfu = option && option.dataset.ifu === '1';
        const keepFrozen = this.hasFrozenTvaValue && this.frozenTvaValue !== '' && this.clientTarget.value === this.frozenClientValue;

        if (keepFrozen) {
            return { tva: this.frozenTvaValue, aib: this.frozenAibValue };
        }

        return { tva: this.tvaValue, aib: hasIfu ? this.aibAvecIfuValue : this.aibSansIfuValue };
    }
}
