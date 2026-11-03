<?php

namespace App\Command;

use App\Entity\Role;
use App\Entity\User;
use App\Repository\RoleRepository;
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
    public function __construct(private readonly EntityManagerInterface $em, private readonly UserPasswordHasherInterface $hasher, private readonly RoleRepository $roles)
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
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Code du rôle : agent, admin, direction ou celui d\'un rôle créé (ex. chef_de_projet)', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $role = $this->findRole((string) $input->getOption('role'));
        if (!$role) {
            $io->error(sprintf('Rôle inconnu. Rôles disponibles : %s.', implode(', ', array_map(static fn (Role $r): string => strtolower(substr($r->getCode(), 5)), $this->roles->findAllOrdered()))));

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

    /** Accepte « agent » ou « ROLE_AGENT », sans tenir compte de la casse. */
    private function findRole(string $name): ?Role
    {
        $code = strtoupper(trim($name));

        return $this->roles->findOneByCode(str_starts_with($code, 'ROLE_') ? $code : 'ROLE_'.$code);
    }
}
