<?php

namespace App\Service;

use App\Entity\Personnel;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Cycle de vie des accès : création d'un compte depuis une fiche du personnel, mot de passe temporaire par e-mail,
 * lien de récupération quand l'authentificateur est indisponible.
 *
 * Les e-mails partent tout de suite (pas par la file Messenger) : l'administrateur doit savoir si l'envoi a échoué.
 * Dans tous les cas l'e-mail est envoyé avant l'enregistrement, pour ne rien changer en base s'il échoue.
 * Les méthodes ne font pas de flush sur un compte existant : c'est à l'appelant de le faire.
 */
class AccountProvisioner
{
    public const PASSWORD_VALID_HOURS = 48;
    public const RECOVERY_VALID_MINUTES = 60;

    /** Sans caractères ambigus (0/O, 1/l/I) : le mot de passe est recopié depuis un e-mail. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TransportInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly SiteSettings $site,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $from,
        #[Autowire('%env(DEFAULT_URI)%')] private readonly string $baseUrl,
    ) {
    }

    /**
     * Crée le compte d'une personne, le rattache à sa fiche et lui envoie son mot de passe temporaire.
     *
     * @throws TransportExceptionInterface si l'e-mail n'a pas pu partir : rien n'est enregistré
     */
    public function createAccount(Personnel $person, string $email, Role $role): User
    {
        $user = (new User())->setEmail($email)->setFullName($person->getFullName())->setRole($role);
        $this->issueTemporaryPassword($user, 'welcome');

        $this->em->persist($user);
        $person->setUser($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Nouveau mot de passe temporaire, valable PASSWORD_VALID_HOURS heures, envoyé à l'e-mail du compte.
     * Il ne sert qu'à inscrire l'authentificateur : le compte doit donc ne plus en avoir (voir resetTwoFactor).
     *
     * @param 'welcome'|'resend'|'reset'|'recovery' $reason
     *
     * @throws TransportExceptionInterface
     */
    public function issueTemporaryPassword(User $user, string $reason): void
    {
        $password = $this->generatePassword();
        $user->setPassword($this->hasher->hashPassword($user, $password))
            ->setPasswordExpiresAt($expires = new \DateTimeImmutable(sprintf('+%d hours', self::PASSWORD_VALID_HOURS)));

        $this->send($user, 'Votre accès à '.$this->site->get()->getHeaderTitle(), 'email/temporary_password.txt.twig', [
            'password' => $password,
            'reason' => $reason,
            'expires' => $expires,
            'hours' => self::PASSWORD_VALID_HOURS,
            'loginUrl' => $this->url('app_login'),
        ]);
    }

    /**
     * Réinitialise l'authentificateur et envoie un nouveau mot de passe temporaire pour le réinscrire.
     *
     * @param 'reset'|'recovery' $reason
     *
     * @throws TransportExceptionInterface
     */
    public function resetTwoFactor(User $user, string $reason): void
    {
        $user->setTotpSecret(null)->setRecoveryToken(null, null);
        $this->issueTemporaryPassword($user, $reason);
    }

    /** Envoie le lien de récupération (à usage unique, valable RECOVERY_VALID_MINUTES minutes). @throws TransportExceptionInterface */
    public function sendRecoveryLink(User $user): void
    {
        $token = bin2hex(random_bytes(32));
        $user->setRecoveryToken(hash('sha256', $token), new \DateTimeImmutable(sprintf('+%d minutes', self::RECOVERY_VALID_MINUTES)));

        $this->send($user, 'Récupération de votre accès à '.$this->site->get()->getHeaderTitle(), 'email/recovery.txt.twig', [
            'url' => $this->url('app_recovery_confirm', ['token' => $token]),
            'minutes' => self::RECOVERY_VALID_MINUTES,
        ]);
        $this->em->flush();
    }

    /** E-mail de contrôle : vérifie que le courrier arrive à cette adresse, sans rien modifier sur le compte. @throws TransportExceptionInterface */
    public function sendTestEmail(User $user): void
    {
        $this->send($user, 'Test d\'envoi d\'e-mail – '.$this->site->get()->getHeaderTitle(), 'email/test.txt.twig', [
            'sentAt' => new \DateTimeImmutable(),
        ]);
    }

    /** Le compte actif auquel appartient ce jeton, tant qu'il est valide. */
    public function findByRecoveryToken(string $token): ?User
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $user = $this->em->getRepository(User::class)->findOneBy(['recoveryTokenHash' => hash('sha256', $token)]);

        return $user && $user->isActive() && $user->hasValidRecoveryToken() ? $user : null;
    }

    public function generatePassword(int $length = 12): string
    {
        $password = '';
        for ($i = 0; $i < $length; ++$i) {
            $password .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return $password;
    }

    /** @param array<string, mixed> $context */
    private function send(User $user, string $subject, string $template, array $context): void
    {
        $email = (new TemplatedEmail())
            ->from($this->from)
            ->to($user->getEmail())
            ->subject($subject)
            ->textTemplate($template)
            ->context($context + ['user' => $user, 'siteTitle' => $this->site->get()->getHeaderTitle()]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            if ($this->em->contains($user)) {
                $this->em->refresh($user); // annule le mot de passe ou le jeton posé en mémoire
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $parameters */
    private function url(string $route, array $parameters = []): string
    {
        // Adresse du site réglée (DEFAULT_URI) et non l'en-tête Host de la requête, qui peut être forgé.
        return rtrim($this->baseUrl, '/').$this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
