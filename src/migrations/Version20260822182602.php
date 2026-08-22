<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-006 — the child purchase approval workflow (FR-090 … FR-099).
 *
 * Three new tables, no change to any existing column, no backfill: safe against a populated
 * database with no window.
 *
 * The parts worth reading:
 *
 *  - **`purchase_approval_request.version`** is Doctrine's optimistic lock, and it is the whole
 *    of NFR-092. Two approvals that arrive at the same instant both read version *n*; one
 *    `UPDATE … WHERE version = n` wins and the other matches no rows, so the payment processor is
 *    reached exactly once. Nothing in the application writes this column directly.
 *  - **`IDX_APPROVAL_DUE` on `(status, expires_at)`** serves the expiry sweep (FR-096, NFR-091),
 *    which asks for pending rows past their mark every few minutes forever. The column order is
 *    the query's: an equality on status, then a range on expires_at, so the whole predicate is
 *    answered from the index.
 *  - **`IDX_APPROVAL_PARENT_STATUS` on `(parent_id, status)`** serves the parent's review screen,
 *    which is the other query this table exists for.
 *  - **`child_spending_setting` is unique on `child_profile_id`** because BR-096 makes the
 *    setting per child. One row per child, enforced by the schema rather than by the service that
 *    happens to write it today.
 *
 * **`purchase_reference` carries no foreign key, and that is a known debt.** Epic-02 owns events
 * and Epic-05 owns payments; neither table exists, so there is nothing to reference. The column
 * is a string wide enough for whatever identifier those epics bring, and `purchase_description`
 * keeps the row readable in the meantime. Adding the real key belongs to Epic-02's first
 * migration, next to R6's `coach_availability_override.event_id`.
 *
 * **Deletion behaviour differs by table, deliberately.** A purchase request is part of the record
 * FR-098 requires to survive, so both of its person keys are `RESTRICT`, exactly like every other
 * person-referencing key in this epic: an erasure anonymizes the account and leaves the trail
 * intact. The two supporting tables are not that record — a spending setting describes a profile
 * that no longer exists, and a notification is a message to an account that is gone — so both
 * cascade, and `child_spending_setting.updated_by_id` is `SET NULL` so an erased parent does not
 * take a still-meaningful setting with them.
 */
final class Version20260822182602 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TASK-006: create purchase_approval_request, child_spending_setting and approval_notification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE approval_notification_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE child_spending_setting_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE purchase_approval_request_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE approval_notification (id INT NOT NULL, recipient_id INT NOT NULL, kind VARCHAR(32) NOT NULL, summary VARCHAR(255) NOT NULL, body TEXT NOT NULL, purchase_approval_request_id INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_48414731E92F8F78 ON approval_notification (recipient_id)');
        $this->addSql('CREATE INDEX IDX_APPROVAL_NOTIFICATION_INBOX ON approval_notification (recipient_id, read_at, created_at)');
        $this->addSql('COMMENT ON COLUMN approval_notification.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN approval_notification.read_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE child_spending_setting (id INT NOT NULL, child_profile_id INT NOT NULL, updated_by_id INT DEFAULT NULL, allow_token_spending_without_approval BOOLEAN DEFAULT false NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_DC36C046896DBBDE ON child_spending_setting (updated_by_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CHILD_SPENDING_SETTING_CHILD ON child_spending_setting (child_profile_id)');
        $this->addSql('COMMENT ON COLUMN child_spending_setting.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE purchase_approval_request (id INT NOT NULL, child_profile_id INT NOT NULL, parent_id INT NOT NULL, version INT DEFAULT 1 NOT NULL, purchase_reference VARCHAR(128) NOT NULL, purchase_description VARCHAR(255) NOT NULL, amount_minor INT NOT NULL, currency VARCHAR(3) NOT NULL, payment_type VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, responded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, parent_notes TEXT DEFAULT NULL, payment_reference VARCHAR(128) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_7E13B0FB2825ECD3 ON purchase_approval_request (child_profile_id)');
        $this->addSql('CREATE INDEX IDX_7E13B0FB727ACA70 ON purchase_approval_request (parent_id)');
        $this->addSql('CREATE INDEX IDX_APPROVAL_PARENT_STATUS ON purchase_approval_request (parent_id, status)');
        $this->addSql('CREATE INDEX IDX_APPROVAL_DUE ON purchase_approval_request (status, expires_at)');
        $this->addSql('CREATE INDEX IDX_APPROVAL_CHILD ON purchase_approval_request (child_profile_id, requested_at)');
        $this->addSql('COMMENT ON COLUMN purchase_approval_request.requested_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN purchase_approval_request.responded_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN purchase_approval_request.expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE approval_notification ADD CONSTRAINT FK_48414731E92F8F78 FOREIGN KEY (recipient_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE child_spending_setting ADD CONSTRAINT FK_DC36C0462825ECD3 FOREIGN KEY (child_profile_id) REFERENCES player_profile (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE child_spending_setting ADD CONSTRAINT FK_DC36C046896DBBDE FOREIGN KEY (updated_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_approval_request ADD CONSTRAINT FK_7E13B0FB2825ECD3 FOREIGN KEY (child_profile_id) REFERENCES player_profile (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_approval_request ADD CONSTRAINT FK_7E13B0FB727ACA70 FOREIGN KEY (parent_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // Drops only what this migration created. The generated `CREATE SCHEMA public` was
        // removed for the reason given in Version20260822144052: the schema predates these
        // migrations, and recreating it fails wherever it still exists.
        $this->addSql('DROP SEQUENCE approval_notification_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE child_spending_setting_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE purchase_approval_request_id_seq CASCADE');
        $this->addSql('ALTER TABLE approval_notification DROP CONSTRAINT FK_48414731E92F8F78');
        $this->addSql('ALTER TABLE child_spending_setting DROP CONSTRAINT FK_DC36C0462825ECD3');
        $this->addSql('ALTER TABLE child_spending_setting DROP CONSTRAINT FK_DC36C046896DBBDE');
        $this->addSql('ALTER TABLE purchase_approval_request DROP CONSTRAINT FK_7E13B0FB2825ECD3');
        $this->addSql('ALTER TABLE purchase_approval_request DROP CONSTRAINT FK_7E13B0FB727ACA70');
        $this->addSql('DROP TABLE approval_notification');
        $this->addSql('DROP TABLE child_spending_setting');
        $this->addSql('DROP TABLE purchase_approval_request');
    }
}
