<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-user', description: 'Crée ou met à jour un utilisateur')]
class CreateUserCommand extends Command
{
    private const ROLES = ['agent' => User::ROLE_AGENT, 'admin' => User::ROLE_ADMIN, 'direction' => User::ROLE_DIRECTION];

    public function __construct(private readonly EntityManagerInterface $em, private readonly UserPasswordHasherInterface $hasher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom complet')
            ->addOption('reset-2fa', null, InputOption::VALUE_NONE, 'Réinitialise Google Authenticator')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'agent, admin ou direction', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $role = self::ROLES[$input->getOption('role')] ?? null;
        if (!$role) {
            $io->error('Rôle invalide : utilisez agent, admin ou direction.');

            return Command::INVALID;
        }

        $email = mb_strtolower(trim($input->getArgument('email')));
        if (!User::isAllowedEmail($email)) {
            $io->error('E-mail refusé : seuls @'.implode(', @', User::ALLOWED_DOMAINS).' sont acceptés.');

            return Command::INVALID;
        }
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]) ?? (new User())->setEmail($email);
        $user->setFullName($input->getOption('name') ?: ($user->getFullName() ?: strstr($email, '@', true)))
            ->setRole($role)
            ->setActive(true)
            ->setPassword($this->hasher->hashPassword($user, $input->getArgument('password')));
        if ($input->getOption('reset-2fa')) {
            $user->setTotpSecret(null);
        }
        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('%s enregistré (%s).', $email, $user->getRoleLabel()));

        return Command::SUCCESS;
    }
}
