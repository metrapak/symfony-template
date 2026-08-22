<?php

declare(strict_types=1);

namespace App\Approval\Command;

use App\Approval\Service\ApprovalExpiryHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Expires the purchase approvals whose 48 hours have run out (FR-096, NFR-091).
 *
 * **This must be scheduled, and nothing in the application schedules it.** NFR-091 requires
 * expiry to happen "without an operator present" and within a bounded window of the mark, and
 * Symfony Scheduler is not installed — adding a bundle so that one cron line can live in PHP
 * instead of crontab is not a trade this task makes. So the deployment contract is one entry,
 * and it belongs in the runbook as much as here:
 *
 *     * / 15 * * * *  php bin/console app:approvals:expire      (every fifteen minutes)
 *
 * Fifteen minutes is a suggestion, not a requirement: the bound NFR-091 asks for is whatever
 * interval is chosen, and the command is safe at any of them. It takes nothing but due rows, it
 * is idempotent — a second run while the first is still working finds the same rows already
 * decided and does nothing (`ApprovalWorkflow::expire()` returns false) — and it is safe to run
 * by hand.
 *
 * A run that expires nothing is the normal case and exits successfully. Silence is the point:
 * a cron entry that emails on every quiet run is a cron entry an operator filters away.
 */
#[AsCommand(
    name: 'app:approvals:expire',
    description: 'Expires child purchase approval requests older than the approval window (run from cron).',
)]
final class ExpireApprovalRequestsCommand extends Command
{
    private const DEFAULT_BATCH = 200;

    public function __construct(
        private readonly ApprovalExpiryHandler $expiry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'How many requests to expire in this run; the next run picks up any remainder',
            (string) self::DEFAULT_BATCH,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = (int) $input->getOption('limit');

        if ($limit < 1) {
            $io->error('The limit has to be at least 1.');

            return Command::INVALID;
        }

        $expired = $this->expiry->expireDue($limit);

        if (0 === $expired) {
            // Verbose only: see the class note on quiet runs.
            $io->writeln('Nothing was due to expire.', OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Expired %d purchase %s.', $expired, 1 === $expired ? 'request' : 'requests'));

        if ($expired === $limit) {
            // Almost certainly a backlog rather than a coincidence; saying so is how an operator
            // finds out that their interval is too long before a parent does.
            $io->note('The batch was full, so there may be more still due. The next run will take them.');
        }

        return Command::SUCCESS;
    }
}
