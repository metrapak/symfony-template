<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-002 / D1 — `"user"` gains the two profile fields the Super Admin tooling needs.
 *
 * `name` is required for every account (spec §9) and is what anonymization overwrites with
 * "Deleted User" (FR-025), so it must be NOT NULL. Existing rows predate the column, hence
 * the three-statement expand: add nullable, backfill, then constrain. Doing it in that order
 * means the migration is safe to run against a populated database instead of failing on the
 * first row.
 *
 * Also adds the composite index the user directory filters on (NFR-020: 10,000 users under
 * three seconds). `email` already carries UNIQ_IDENTIFIER_EMAIL and needs nothing further.
 */
final class Version20260822120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TASK-002: add user.name (backfilled, NOT NULL) and user.phone; index (role, status)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD phone VARCHAR(32) DEFAULT NULL');

        // The local part of a normalized address: never empty, because User::setEmail() is
        // the only write path and every stored address has passed an Email constraint.
        $this->addSql('UPDATE "user" SET name = initcap(split_part(email, \'@\', 1)) WHERE name IS NULL');

        $this->addSql('ALTER TABLE "user" ALTER COLUMN name SET NOT NULL');

        $this->addSql('CREATE INDEX IDX_USER_ROLE_STATUS ON "user" (role, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_USER_ROLE_STATUS');
        $this->addSql('ALTER TABLE "user" DROP phone');
        $this->addSql('ALTER TABLE "user" DROP name');
    }
}
