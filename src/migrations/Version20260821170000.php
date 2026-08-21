<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `user.password_changed_at`, the stamp User::isEqualTo() compares so that replacing a
 * password de-authenticates every other session that account has open.
 *
 * Nullable and not backfilled on purpose: NULL means "never changed since this column
 * existed", which is exactly true for pre-existing rows, and any value invented for them
 * would only differ from what their live sessions already carry — signing those users out
 * for no reason.
 */
final class Version20260821170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.password_changed_at so a password change ends the account\'s other sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD password_changed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN "user".password_changed_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP password_changed_at');
    }
}
