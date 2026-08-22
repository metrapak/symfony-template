<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-003 — the invitation and membership schema (FR-040 … FR-049).
 *
 * Five new tables and no changes to existing ones, so this is additive and safe to run against
 * a populated database.
 *
 * Three constraints in here are load-bearing, and none of them is duplicated application logic:
 *
 *  - `UNIQ_SHARE_LINK_CODE` — a code resolves to at most one link, which is what lets the
 *    resolver treat a lookup as authoritative.
 *  - `UNIQ_ASSOCIATION_ORG_PLAYER` — one association per (organization, player). This is
 *    FR-043's idempotency guarantee: two concurrent redemptions of one link both pass the
 *    service's "already associated?" check, and the index is what makes the second a no-op
 *    rather than a duplicate roster entry.
 *  - `UNIQ_COACH_ASSIGNMENT_ACTIVE_COACH` — a **partial** unique index on `coach_id` where the
 *    status is active, which is BR-044. A plain unique index cannot express it: a coach who
 *    leaves one organization for another must keep the ended row, and a full unique index
 *    would forbid the replacement forever. FR-045 requires exactly this — "a database
 *    constraint plus a service check, not UI-only".
 *
 * PostgreSQL is the only platform this project targets, which is what makes the predicate
 * available; it is declared on the entity as well so `doctrine:schema:validate` sees mapping
 * and database agree.
 */
final class Version20260822144052 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TASK-003: create player_profile, share_link, trainer_player_association, coach_assignment (partial unique active coach) and share_link_redemption';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE coach_assignment_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE player_profile_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE share_link_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE share_link_redemption_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE trainer_player_association_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE coach_assignment (id INT NOT NULL, organization_id INT NOT NULL, coach_id INT NOT NULL, via_share_link_id INT DEFAULT NULL, joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(16) NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_5E38334932C8A3DE ON coach_assignment (organization_id)');
        $this->addSql('CREATE INDEX IDX_5E3833493C105691 ON coach_assignment (coach_id)');
        $this->addSql('CREATE INDEX IDX_5E38334984B30490 ON coach_assignment (via_share_link_id)');
        $this->addSql('CREATE INDEX IDX_COACH_ASSIGNMENT_ORG_STATUS ON coach_assignment (organization_id, status)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_COACH_ASSIGNMENT_ACTIVE_COACH ON coach_assignment (coach_id) WHERE (status = \'active\')');
        $this->addSql('COMMENT ON COLUMN coach_assignment.joined_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN coach_assignment.ended_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE player_profile (id INT NOT NULL, owner_id INT NOT NULL, account_id INT DEFAULT NULL, display_name VARCHAR(255) NOT NULL, birth_date DATE DEFAULT NULL, gender VARCHAR(16) DEFAULT NULL, child BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_PLAYER_PROFILE_OWNER ON player_profile (owner_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PLAYER_PROFILE_ACCOUNT ON player_profile (account_id)');
        $this->addSql('COMMENT ON COLUMN player_profile.birth_date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN player_profile.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN player_profile.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE share_link (id INT NOT NULL, organization_id INT NOT NULL, created_by_id INT NOT NULL, code VARCHAR(32) NOT NULL, type VARCHAR(16) NOT NULL, target_email VARCHAR(180) DEFAULT NULL, target_name VARCHAR(255) DEFAULT NULL, message TEXT DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, max_uses INT DEFAULT NULL, use_count INT DEFAULT 0 NOT NULL, active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_8B6B946832C8A3DE ON share_link (organization_id)');
        $this->addSql('CREATE INDEX IDX_8B6B9468B03A8386 ON share_link (created_by_id)');
        $this->addSql('CREATE INDEX IDX_SHARE_LINK_ORG_TYPE ON share_link (organization_id, type)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SHARE_LINK_CODE ON share_link (code)');
        $this->addSql('COMMENT ON COLUMN share_link.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN share_link.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN share_link.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE share_link_redemption (id INT NOT NULL, share_link_id INT NOT NULL, user_id INT NOT NULL, redeemed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, outcome VARCHAR(32) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_BD1A1D40EFC8A8ED ON share_link_redemption (share_link_id)');
        $this->addSql('CREATE INDEX IDX_BD1A1D40A76ED395 ON share_link_redemption (user_id)');
        $this->addSql('CREATE INDEX IDX_REDEMPTION_LINK_TIME ON share_link_redemption (share_link_id, redeemed_at)');
        $this->addSql('COMMENT ON COLUMN share_link_redemption.redeemed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE trainer_player_association (id INT NOT NULL, organization_id INT NOT NULL, player_profile_id INT NOT NULL, via_share_link_id INT DEFAULT NULL, connected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(16) NOT NULL, deactivated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_DF84E46632C8A3DE ON trainer_player_association (organization_id)');
        $this->addSql('CREATE INDEX IDX_DF84E466935F9685 ON trainer_player_association (player_profile_id)');
        $this->addSql('CREATE INDEX IDX_DF84E46684B30490 ON trainer_player_association (via_share_link_id)');
        $this->addSql('CREATE INDEX IDX_ASSOCIATION_ORG_STATUS ON trainer_player_association (organization_id, status)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ASSOCIATION_ORG_PLAYER ON trainer_player_association (organization_id, player_profile_id)');
        $this->addSql('COMMENT ON COLUMN trainer_player_association.connected_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN trainer_player_association.deactivated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE coach_assignment ADD CONSTRAINT FK_5E38334932C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE coach_assignment ADD CONSTRAINT FK_5E3833493C105691 FOREIGN KEY (coach_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE coach_assignment ADD CONSTRAINT FK_5E38334984B30490 FOREIGN KEY (via_share_link_id) REFERENCES share_link (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE player_profile ADD CONSTRAINT FK_E0A3554A7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE player_profile ADD CONSTRAINT FK_E0A3554A9B6B5FBA FOREIGN KEY (account_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE share_link ADD CONSTRAINT FK_8B6B946832C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE share_link ADD CONSTRAINT FK_8B6B9468B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE share_link_redemption ADD CONSTRAINT FK_BD1A1D40EFC8A8ED FOREIGN KEY (share_link_id) REFERENCES share_link (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE share_link_redemption ADD CONSTRAINT FK_BD1A1D40A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trainer_player_association ADD CONSTRAINT FK_DF84E46632C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trainer_player_association ADD CONSTRAINT FK_DF84E466935F9685 FOREIGN KEY (player_profile_id) REFERENCES player_profile (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trainer_player_association ADD CONSTRAINT FK_DF84E46684B30490 FOREIGN KEY (via_share_link_id) REFERENCES share_link (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // Reverting drops only what this migration created. The generated
        // `CREATE SCHEMA public` was removed: the schema predates this migration and
        // recreating it here would fail on any database where it still exists.
        $this->addSql('DROP SEQUENCE coach_assignment_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE player_profile_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE share_link_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE share_link_redemption_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE trainer_player_association_id_seq CASCADE');
        $this->addSql('ALTER TABLE coach_assignment DROP CONSTRAINT FK_5E38334932C8A3DE');
        $this->addSql('ALTER TABLE coach_assignment DROP CONSTRAINT FK_5E3833493C105691');
        $this->addSql('ALTER TABLE coach_assignment DROP CONSTRAINT FK_5E38334984B30490');
        $this->addSql('ALTER TABLE player_profile DROP CONSTRAINT FK_E0A3554A7E3C61F9');
        $this->addSql('ALTER TABLE player_profile DROP CONSTRAINT FK_E0A3554A9B6B5FBA');
        $this->addSql('ALTER TABLE share_link DROP CONSTRAINT FK_8B6B946832C8A3DE');
        $this->addSql('ALTER TABLE share_link DROP CONSTRAINT FK_8B6B9468B03A8386');
        $this->addSql('ALTER TABLE share_link_redemption DROP CONSTRAINT FK_BD1A1D40EFC8A8ED');
        $this->addSql('ALTER TABLE share_link_redemption DROP CONSTRAINT FK_BD1A1D40A76ED395');
        $this->addSql('ALTER TABLE trainer_player_association DROP CONSTRAINT FK_DF84E46632C8A3DE');
        $this->addSql('ALTER TABLE trainer_player_association DROP CONSTRAINT FK_DF84E466935F9685');
        $this->addSql('ALTER TABLE trainer_player_association DROP CONSTRAINT FK_DF84E46684B30490');
        $this->addSql('DROP TABLE coach_assignment');
        $this->addSql('DROP TABLE player_profile');
        $this->addSql('DROP TABLE share_link');
        $this->addSql('DROP TABLE share_link_redemption');
        $this->addSql('DROP TABLE trainer_player_association');
    }
}
