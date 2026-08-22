<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-002 / D2 — the audit spine: impersonation sessions, the deletion compliance record,
 * and the general audit log.
 *
 * Every foreign key to `"user"` is RESTRICT. FR-026 requires history to survive a user's
 * removal, and this task removes users by anonymizing the row rather than deleting it, so a
 * CASCADE here would only ever fire for a code path that is not supposed to exist. RESTRICT
 * turns that path into a visible error instead of a silent erasure.
 */
final class Version20260822120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TASK-002: create impersonation_session, user_deletion_record and audit_log_entry';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE impersonation_session_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE user_deletion_record_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE audit_log_entry_id_seq INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql(<<<'SQL'
            CREATE TABLE impersonation_session (
                id INT NOT NULL,
                admin_id INT NOT NULL,
                target_user_id INT NOT NULL,
                started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                duration_seconds INT DEFAULT NULL,
                end_reason VARCHAR(16) DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('COMMENT ON COLUMN impersonation_session.started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN impersonation_session.ended_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_IMPERSONATION_TARGET_STARTED ON impersonation_session (target_user_id, started_at)');
        $this->addSql('CREATE INDEX IDX_IMPERSONATION_ADMIN_ENDED ON impersonation_session (admin_id, ended_at)');
        $this->addSql('CREATE INDEX IDX_C7AA2315642B8210 ON impersonation_session (admin_id)');
        $this->addSql('CREATE INDEX IDX_C7AA23156C066AFE ON impersonation_session (target_user_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE user_deletion_record (
                id INT NOT NULL,
                deleted_by_id INT NOT NULL,
                original_user_id INT NOT NULL,
                original_email_digest VARCHAR(64) NOT NULL,
                anonymized_email VARCHAR(180) NOT NULL,
                reason TEXT NOT NULL,
                deleted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('COMMENT ON COLUMN user_deletion_record.deleted_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_DELETION_EMAIL_DIGEST ON user_deletion_record (original_email_digest)');
        $this->addSql('CREATE INDEX IDX_FB52834AC76F1F52 ON user_deletion_record (deleted_by_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE audit_log_entry (
                id INT NOT NULL,
                actor_id INT NOT NULL,
                impersonator_id INT DEFAULT NULL,
                action VARCHAR(64) NOT NULL,
                subject_type VARCHAR(64) DEFAULT NULL,
                subject_id INT DEFAULT NULL,
                payload JSON NOT NULL,
                occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('COMMENT ON COLUMN audit_log_entry.occurred_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_AUDIT_ACTOR_OCCURRED ON audit_log_entry (actor_id, occurred_at)');
        $this->addSql('CREATE INDEX IDX_AUDIT_SUBJECT ON audit_log_entry (subject_type, subject_id)');
        $this->addSql('CREATE INDEX IDX_D2D938A210DAF24A ON audit_log_entry (actor_id)');
        $this->addSql('CREATE INDEX IDX_D2D938A2D1107CFF ON audit_log_entry (impersonator_id)');

        $this->addSql('ALTER TABLE impersonation_session ADD CONSTRAINT FK_C7AA2315642B8210 FOREIGN KEY (admin_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE impersonation_session ADD CONSTRAINT FK_C7AA23156C066AFE FOREIGN KEY (target_user_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_deletion_record ADD CONSTRAINT FK_FB52834AC76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE audit_log_entry ADD CONSTRAINT FK_D2D938A210DAF24A FOREIGN KEY (actor_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE audit_log_entry ADD CONSTRAINT FK_D2D938A2D1107CFF FOREIGN KEY (impersonator_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE impersonation_session DROP CONSTRAINT FK_C7AA2315642B8210');
        $this->addSql('ALTER TABLE impersonation_session DROP CONSTRAINT FK_C7AA23156C066AFE');
        $this->addSql('ALTER TABLE user_deletion_record DROP CONSTRAINT FK_FB52834AC76F1F52');
        $this->addSql('ALTER TABLE audit_log_entry DROP CONSTRAINT FK_D2D938A210DAF24A');
        $this->addSql('ALTER TABLE audit_log_entry DROP CONSTRAINT FK_D2D938A2D1107CFF');

        $this->addSql('DROP TABLE audit_log_entry');
        $this->addSql('DROP TABLE user_deletion_record');
        $this->addSql('DROP TABLE impersonation_session');

        $this->addSql('DROP SEQUENCE audit_log_entry_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE user_deletion_record_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE impersonation_session_id_seq CASCADE');
    }
}
