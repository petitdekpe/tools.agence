<?php

namespace App\Service;

use App\Entity\User;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use OTPHP\TOTP;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Google Authenticator (TOTP, RFC 6238) : génération, chiffrement du secret et vérification des codes. */
class TotpService
{
    private const LEEWAY = 29; // tolère ±1 pas de 30 s (décalage d'horloge)
    private string $key;

    public function __construct(
        #[Autowire('%kernel.secret%')] string $secret,
        private readonly SiteSettings $site,
    ) {
        $this->key = sodium_crypto_generichash('totp-secret-key|'.$secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function generateSecret(): string
    {
        return TOTP::generate(null, 20)->getSecret();
    }

    public function provisioningUri(string $secret, string $email): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($email);
        $totp->setIssuer($this->site->get()->getHeaderTitle());

        return $totp->getProvisioningUri();
    }

    public function qrDataUri(string $uri): string
    {
        return (new Builder(writer: new SvgWriter(), data: $uri, size: 220, margin: 0))->build()->getDataUri();
    }

    /** Vérifie un code pour un secret en clair (inscription). */
    public function verifyPlain(string $secret, string $code): bool
    {
        return 1 === preg_match('/^\d{6}$/', $code) && TOTP::createFromSecret($secret)->verify($code, null, self::LEEWAY);
    }

    /** Vérifie un code pour un utilisateur inscrit ; un même code ne peut servir qu'une fois. */
    public function verifyForUser(User $user, string $code): bool
    {
        $code = trim(str_replace(' ', '', $code));
        if (!$user->hasTwoFactor() || !$this->verifyPlain($this->decrypt($user->getTotpSecret()), $code)) {
            return false;
        }

        $last = $user->getTotpLastCodeAt();
        if ($user->getTotpLastCode() === $code && $last && $last > new \DateTimeImmutable('-90 seconds')) {
            return false;
        }
        $user->rememberTotpCode($code);

        return true;
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $stored): string
    {
        $raw = base64_decode($stored, true) ?: '';
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);

        return false === $plain ? '' : $plain;
    }
}
