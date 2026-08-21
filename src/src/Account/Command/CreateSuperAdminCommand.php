<?php

declare(strict_types=1);

namespace App\Account\Command;

use App\Account\Exception\EmailAlreadyRegistered;
use App\Account\Service\SuperAdminCreator;
use App\Shared\Domain\Validator\Constraint\PasswordRequirements;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:account:create-super-admin',
    description: 'Creates the first Super Admin account (no UI path to this exists).',
)]
final class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private readonly SuperAdminCreator $superAdminCreator,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address to sign in with')
            ->addArgument('password', InputArgument::OPTIONAL, 'Password; prompted for (hidden) when omitted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        $emailErrors = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);

        if ($emailErrors->count() > 0) {
            $io->error(\sprintf('"%s" is not a valid email address.', $email));

            return Command::FAILURE;
        }

        $password = $input->getArgument('password');

        if (null === $password) {
            // Prompted rather than passed as an argument so the password stays out of shell
            // history and the process list.
            $question = (new Question('Password: '))->setHidden(true)->setHiddenFallback(false);
            $password = $io->askQuestion($question);
        }

        $password = (string) $password;
        $passwordErrors = $this->validator->validate($password, [new PasswordRequirements()]);

        if ($passwordErrors->count() > 0) {
            $io->error('The password does not meet the requirements:');
            foreach ($passwordErrors as $violation) {
                $io->writeln(\sprintf('  - %s', $violation->getMessage()));
            }

            return Command::FAILURE;
        }

        try {
            $user = $this->superAdminCreator->create($email, $password);
        } catch (EmailAlreadyRegistered $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Super Admin "%s" created (id %d).', $user->getEmail(), $user->getId()));

        return Command::SUCCESS;
    }
}
