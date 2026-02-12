<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:insert-products',
    description: 'Insert products using high-performance sequence pre-allocation and batched DBAL.',
)]
class InsertProductsCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 500;
    private const COLUMNS = ['id', 'name', 'price', 'description', 'category_id', 'created_at'];

    public function __construct(
        private Connection $conn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('products', 'p', InputOption::VALUE_OPTIONAL, 'Number of products', 10000)
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Products per single INSERT', self::DEFAULT_BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $numProducts = (int) $input->getOption('products');
        $batchSize = (int) $input->getOption('batch-size');

        $categoryIds = $this->getCategoryIds();
        if (empty($categoryIds)) {
            $io->error('No categories found. Please seed categories first.');

            return Command::FAILURE;
        }

        $io->note("Pre-allocating $numProducts IDs from sequence...");
        $firstId = (int) $this->conn->fetchOne("SELECT nextval('product_id_seq') FROM generate_series(1, $numProducts) LIMIT 1");

        $io->progressStart($numProducts);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $batchParams = [];

        for ($i = 0; $i < $numProducts; ++$i) {
            $currentId = $firstId + $i;

            $batchParams[] = $currentId;
            $batchParams[] = 'Product ' . ($i + 1);
            $batchParams[] = random_int(100, 100000);
            $batchParams[] = 'Automatically generated product #' . ($i + 1);
            $batchParams[] = $categoryIds[array_rand($categoryIds)];
            $batchParams[] = $now;

            if (count($batchParams) / count(self::COLUMNS) >= $batchSize || $i === $numProducts - 1) {
                $rowsInThisBatch = count($batchParams) / count(self::COLUMNS);
                $this->insertBatch($batchParams, (int) $rowsInThisBatch);

                $io->progressAdvance($rowsInThisBatch);
                $batchParams = [];
            }
        }

        $io->progressFinish();
        $io->success("Successfully inserted $numProducts products.");

        return Command::SUCCESS;
    }

    private function getCategoryIds(): array
    {
        return $this->conn->fetchFirstColumn('SELECT id FROM category');
    }

    private function insertBatch(array $params, int $rowCount): void
    {
        $colCount = count(self::COLUMNS);
        $rowPlaceholders = '(' . implode(',', array_fill(0, $colCount, '?')) . ')';
        $allPlaceholders = implode(',', array_fill(0, $rowCount, $rowPlaceholders));

        $sql = sprintf(
            'INSERT INTO product (%s) VALUES %s',
            implode(',', self::COLUMNS),
            $allPlaceholders,
        );

        $this->conn->executeStatement($sql, $params);
    }
}
