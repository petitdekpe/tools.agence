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
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-superadmin', description: 'Crée (ou réinitialise) le compte administrateur principal')]
class CreateSuperAdminCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly UserPasswordHasherInterface $hasher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Sans argument, la commande pose les questions (le mot de passe est saisi en masqué). Si le compte existe déjà, il devient admin, est réactivé et son mot de passe est remplacé. Google Authenticator est à nouveau demandé à la première connexion.')
            ->addArgument('email', InputArgument::OPTIONAL, 'E-mail (@'.implode(', @', User::ALLOWED_DOMAINS).')')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom complet')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe initial (à éviter : préférez la saisie masquée)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = mb_strtolower(trim((string) ($input->getArgument('email') ?? $io->ask('E-mail', null, $this->validateEmail(...)))));
        if (!User::isAllowedEmail($email)) {
            $io->error('E-mail refusé : seuls @'.implode(', @', User::ALLOWED_DOMAINS).' sont acceptés.');

            return Command::INVALID;
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        $isNew = null === $user;
        $user ??= (new User())->setEmail($email);

        $name = $input->getOption('name') ?: ($input->isInteractive()
            ? $io->ask('Nom complet', $user->getFullName() ?: ucfirst((string) strstr($email, '@', true)))
            : ($user->getFullName() ?: ucfirst((string) strstr($email, '@', true))));

        $password = $input->getOption('password');
        if (null === $password) {
            if (!$input->isInteractive()) {
                $io->error('Mode non interactif : fournissez --password.');

                return Command::INVALID;
            }
            $password = $io->askQuestion($this->hiddenQuestion('Mot de passe initial (8 caractères minimum)', $this->validatePassword(...)));
            if ($password !== $io->askQuestion($this->hiddenQuestion('Confirmez le mot de passe'))) {
                $io->error('Les mots de passe ne correspondent pas.');

                return Command::INVALID;
            }
        } elseif (mb_strlen($password) < 8) {
            $io->error('Le mot de passe doit faire au moins 8 caractères.');

            return Command::INVALID;
        }

        $user->setFullName($name)
            ->setRole(User::ROLE_ADMIN)
            ->setActive(true)
            ->setTotpSecret(null)
            ->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Admin %s : %s.', $isNew ? 'créé' : 'mis à jour', $email));
        $io->note('À la première connexion : saisir le mot de passe (sans code) via « Utiliser un mot de passe », puis activer Google Authenticator.');

        return Command::SUCCESS;
    }

    private function hiddenQuestion(string $label, ?callable $validator = null): Question
    {
        return (new Question($label))->setHidden(true)->setMaxAttempts(3)->setValidator($validator);
    }

    private function validateEmail(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        if (!filter_var($value, \FILTER_VALIDATE_EMAIL) || !User::isAllowedEmail($value)) {
            throw new \RuntimeException('E-mail invalide ou domaine refusé (@'.implode(', @', User::ALLOWED_DOMAINS).').');
        }

        return $value;
    }

    private function validatePassword(?string $value): string
    {
        if (mb_strlen((string) $value) < 8) {
            throw new \RuntimeException('8 caractères minimum.');
        }

        return $value;
    }
}
