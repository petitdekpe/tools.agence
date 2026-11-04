<?php

namespace App\Service;

use App\Entity\Personnel;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Envoi des e-mails d'information (absence décidée, ticket pris en charge, matériel remis...).
 *
 * Contrairement aux e-mails de compte (AccountProvisioner), une notification ne doit jamais faire échouer l'action
 * qui l'a provoquée : l'enregistrement est déjà fait, une erreur d'envoi est seulement consignée dans les logs.
 * La personne qui agit n'est jamais notifiée de sa propre action, et chaque destinataire ne reçoit qu'un message.
 */
class Notifier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PermissionManager $permissions,
        private readonly TransportInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly SiteSettings $site,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $from,
        #[Autowire('%env(DEFAULT_URI)%')] private readonly string $baseUrl,
    ) {
    }

    /** @return list<User> les comptes actifs dont le rôle accorde cette permission */
    public function holders(string $permission): array
    {
        return array_values(array_filter($this->activeUsers(), fn (User $u): bool => $this->permissions->has($u, $permission)));
    }

    /** @return list<User> les administrateurs actifs */
    public function admins(): array
    {
        return array_values(array_filter($this->activeUsers(), static fn (User $u): bool => (bool) $u->getRole()?->isAdmin()));
    }

    /**
     * @param iterable<User|Personnel|null> $recipients un membre du personnel est remplacé par son compte, s'il en a un
     * @param list<string>                  $lines      paragraphes du message
     * @param string|null                   $route      page à ouvrir depuis le message
     * @param array<string, mixed>          $routeParams
     */
    public function send(iterable $recipients, string $subject, array $lines, ?string $route = null, array $routeParams = []): void
    {
        $users = $this->resolve($recipients);
        if (!$users) {
            return;
        }

        $title = $this->site->get()->getHeaderTitle();
        $url = $route ? rtrim($this->baseUrl, '/').$this->urls->generate($route, $routeParams, UrlGeneratorInterface::ABSOLUTE_PATH) : null;

        foreach ($users as $user) {
            try {
                $this->mailer->send((new TemplatedEmail())
                    ->from($this->from)
                    ->to($user->getEmail())
                    ->subject('['.$title.'] '.$subject)
                    ->textTemplate('email/notification.txt.twig')
                    ->context(['user' => $user, 'lines' => $lines, 'url' => $url, 'siteTitle' => $title]));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Notification « %s » non envoyée à %s : %s', $subject, $user->getEmail(), $e->getMessage()));
            }
        }
    }

    /**
     * @param iterable<User|Personnel|null> $recipients
     *
     * @return list<User>
     */
    private function resolve(iterable $recipients): array
    {
        $actor = $this->security->getUser();
        $users = [];
        foreach ($recipients as $recipient) {
            $user = $recipient instanceof Personnel ? $recipient->getUser() : $recipient;
            if (!$user instanceof User || !$user->isActive() || '' === $user->getEmail()) {
                continue;
            }
            if ($actor instanceof User && $actor->getId() === $user->getId()) {
                continue;
            }
            $users[$user->getId()] = $user;
        }

        return array_values($users);
    }

    /** @return list<User> */
    private function activeUsers(): array
    {
        return $this->em->getRepository(User::class)->findBy(['active' => true]);
    }
}
