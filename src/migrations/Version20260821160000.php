<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-001 expand phase: widen "user" for the account lifecycle, backfill the single
 * primary role from the legacy `roles` JSON array, and create the `organization` and
 * `reset_password_request` tables.
 *
 * `roles` is deliberately NOT dropped here — the contract phase does that separately so
 * this migration can be deployed, verified and reverted on its own.
 */
final class Version20260821160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TASK-001 expand: user account lifecycle columns, role backfill, organization and reset_password_request tables';
    }

    public function up(Schema $schema): void
    {
        // Emails become the canonical lowercase identifier. Merging two rows that differ
        // only by case would silently destroy an account, so refuse rather than guess.
        $collisions = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT LOWER(TRIM(email)) FROM "user" GROUP BY LOWER(TRIM(email)) HAVING COUNT(*) > 1) c'
        );
        $this->abortIf(
            $collisions > 0,
            \sprintf('Found %d email address(es) that differ only by case in "user"; resolve them before migrating.', $collisions)
        );

        // Added nullable/defaulted so existing rows stay valid; NOT NULL is set once backfilled.
        $this->addSql('ALTER TABLE "user" ADD role VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD status VARCHAR(16) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD must_change_password BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('COMMENT ON COLUMN "user".email_verified_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "user".last_login_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "user".created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "user".updated_at IS \'(DC2Type:datetime_immutable)\'');

        // Collapse the legacy roles array to one primary role, most privileged wins.
        // jsonb_exists() rather than the `?` operator: `?` collides with Doctrine's
        // positional parameter placeholder and throws at execution time.
        $this->addSql(<<<'SQL'
            UPDATE "user" SET role = CASE
                WHEN jsonb_exists(roles::jsonb, 'ROLE_SUPER_ADMIN') THEN 'ROLE_SUPER_ADMIN'
                WHEN jsonb_exists(roles::jsonb, 'ROLE_TRAINER')     THEN 'ROLE_TRAINER'
                WHEN jsonb_exists(roles::jsonb, 'ROLE_COACH')       THEN 'ROLE_COACH'
                ELSE 'ROLE_PLAYER'
            END
            SQL);

        // Normalize existing identifiers to match the write path. NOT reversible — see down().
        $this->addSql('UPDATE "user" SET email = LOWER(TRIM(email))');

        // The mapping declares no database-level default for these, so drop the ones that
        // only existed to keep pre-existing rows valid. Otherwise schema:validate reports drift.
        $this->addSql('ALTER TABLE "user" ALTER status DROP DEFAULT');
        $this->addSql('ALTER TABLE "user" ALTER created_at DROP DEFAULT');
        $this->addSql('ALTER TABLE "user" ALTER updated_at DROP DEFAULT');

        $this->addSql('CREATE SEQUENCE organization_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE organization (id INT NOT NULL, owner_id INT NOT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ORGANIZATION_OWNER ON organization (owner_id)');
        $this->addSql('COMMENT ON COLUMN organization.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN organization.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE organization ADD CONSTRAINT FK_C1EE637C7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE SEQUENCE reset_password_request_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE reset_password_request (id INT NOT NULL, user_id INT NOT NULL, selector VARCHAR(20) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_7CE748AA76ED395 ON reset_password_request (user_id)');
        $this->addSql('CREATE INDEX IDX_RESET_PASSWORD_SELECTOR ON reset_password_request (selector)');
        $this->addSql('COMMENT ON COLUMN reset_password_request.requested_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN reset_password_request.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE reset_password_request ADD CONSTRAINT FK_7CE748AA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reset_password_request DROP CONSTRAINT FK_7CE748AA76ED395');
        $this->addSql('DROP TABLE reset_password_request');
        $this->addSql('DROP SEQUENCE reset_password_request_id_seq CASCADE');

        $this->addSql('ALTER TABLE organization DROP CONSTRAINT FK_C1EE637C7E3C61F9');
        $this->addSql('DROP TABLE organization');
        $this->addSql('DROP SEQUENCE organization_id_seq CASCADE');

        $this->addSql('ALTER TABLE "user" DROP role');
        $this->addSql('ALTER TABLE "user" DROP status');
        $this->addSql('ALTER TABLE "user" DROP email_verified_at');
        $this->addSql('ALTER TABLE "user" DROP must_change_password');
        $this->addSql('ALTER TABLE "user" DROP last_login_at');
        $this->addSql('ALTER TABLE "user" DROP created_at');
        $this->addSql('ALTER TABLE "user" DROP updated_at');

        // Lowercasing email addresses is not reversible: the original casing is gone.
        // `roles` was never dropped by up(), so no role data needs restoring here.
    }
}
