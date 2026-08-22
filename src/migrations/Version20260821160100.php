<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-001 contract phase: retire the legacy `roles` JSON array now that every row carries
 * a single primary role (FR-007, BR-002).
 *
 * Split from the expand migration so the backfill can be deployed and verified before the
 * source column disappears.
 */
final class Version20260821160100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TASK-001 contract: drop the legacy user.roles array and require user.role';
    }

    public function up(Schema $schema): void
    {
        // The expand migration backfilled every row; anything still null means the backfill
        // did not run, and dropping `roles` would destroy the only copy of that data.
        $unbackfilled = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "user" WHERE role IS NULL');
        $this->abortIf(
            $unbackfilled > 0,
            \sprintf('%d user row(s) still have a NULL role; run the expand migration before contracting.', $unbackfilled)
        );

        $this->addSql('ALTER TABLE "user" ALTER role SET NOT NULL');
        $this->addSql('ALTER TABLE "user" DROP roles');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD roles JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ALTER role DROP NOT NULL');

        // Rebuild the array from the single primary role so a rolled-back deployment still
        // authorizes correctly.
        $this->addSql('UPDATE "user" SET roles = json_build_array(role)::json WHERE role IS NOT NULL');
        $this->addSql('ALTER TABLE "user" ALTER roles DROP DEFAULT');
    }
}
