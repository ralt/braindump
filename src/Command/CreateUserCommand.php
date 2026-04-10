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

#[AsCommand(
    name: 'app:create-user',
    description: 'Create a new user account',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address')
            ->addArgument('password', InputArgument::REQUIRED, 'Password')
            ->addArgument('display-name', InputArgument::REQUIRED, 'Display name')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = new User();
        $user->setEmail($input->getArgument('email'));
        $user->setDisplayName($input->getArgument('display-name'));
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, $input->getArgument('password'))
        );

        if ($input->getOption('admin')) {
            $user->setRoles(['ROLE_ADMIN']);
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('User "%s" created%s.', $user->getEmail(), $input->getOption('admin') ? ' with ROLE_ADMIN' : ''));

        return Command::SUCCESS;
    }
}
