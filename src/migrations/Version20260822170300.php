<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-005 — weekly availability and the coach conflict override (FR-080 … FR-088).
 *
 * Two new tables, no change to any existing column, no backfill: safe against a populated
 * database with no window.
 *
 * The parts worth reading are the two indexes on `availability_slot`, because between them they
 * are NFR-080 ("availability queries across thousands of players, fast enough for interactive
 * filtering"):
 *
 *  - `IDX_AVAILABILITY_SLOT_SUBJECT` on `(subject_type, subject_id, day_of_week)` serves the
 *    read-and-replace path: one person's week, which is what every save and every grid render
 *    asks for.
 *  - `IDX_AVAILABILITY_SLOT_LOOKUP` on `(subject_type, day_of_week, available, start_minute,
 *    end_minute)` serves the trainer's filter. The column order is the query's order: the type
 *    and day are equalities, `available` excludes the negative rows, and the two minute columns
 *    are the range comparison — `start_minute <= :start AND end_minute >= :end` — so the whole
 *    predicate is answered from the index without touching the table.
 *
 * Coverage rather than overlap is what makes that predicate two comparisons instead of a
 * bounding-box test, and it is correct only because a saved week is normalized: adjacent ranges
 * are merged before they are written, so 16:00-18:00 plus 18:00-21:00 is one row. See
 * `App\Availability\ValueObject\WeeklySchedule`.
 *
 * **`subject_id` has no foreign key, deliberately.** One table holds players and coaches — the
 * same fact about different people — and the column points at `player_profile` for one and at
 * `"user"` for the other. No column can reference two tables, and splitting into two identical
 * tables to gain the constraint would duplicate the query shape and both indexes. The pairing is
 * enforced in code by `AvailabilitySubject`, which is the only thing that can construct one.
 *
 * **`coach_availability_override.event_id` has no foreign key either, and that one is a debt.**
 * Epic-02 owns events and does not exist; the column is created now, nullable and unconstrained,
 * so Epic-02's assignment flow can start writing it without migrating this table. Adding the
 * foreign key belongs to Epic-02's first migration. Nothing in this task writes a non-null value.
 *
 * `organization_id` on the override is likewise an id and not a relation - it is written from the
 * trainer's tenant at the moment of the override and must not follow a coach who later moves to
 * another trainer (BR-044).
 */
final class Version20260822170300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TASK-005: create availability_slot and coach_availability_override';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE availability_slot_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE coach_availability_override_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE availability_slot (id INT NOT NULL, subject_type VARCHAR(16) NOT NULL, subject_id INT NOT NULL, day_of_week SMALLINT NOT NULL, start_minute SMALLINT NOT NULL, end_minute SMALLINT NOT NULL, available BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_AVAILABILITY_SLOT_SUBJECT ON availability_slot (subject_type, subject_id, day_of_week)');
        $this->addSql('CREATE INDEX IDX_AVAILABILITY_SLOT_LOOKUP ON availability_slot (subject_type, day_of_week, available, start_minute, end_minute)');
        $this->addSql('COMMENT ON COLUMN availability_slot.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN availability_slot.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE coach_availability_override (id INT NOT NULL, coach_id INT NOT NULL, overridden_by_id INT NOT NULL, event_id INT DEFAULT NULL, organization_id INT NOT NULL, day_of_week SMALLINT NOT NULL, start_minute SMALLINT NOT NULL, end_minute SMALLINT NOT NULL, reason TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_65E461A63C105691 ON coach_availability_override (coach_id)');
        $this->addSql('CREATE INDEX IDX_65E461A64594B015 ON coach_availability_override (overridden_by_id)');
        $this->addSql('CREATE INDEX IDX_COACH_OVERRIDE_COACH ON coach_availability_override (coach_id, created_at)');
        $this->addSql('CREATE INDEX IDX_COACH_OVERRIDE_EVENT ON coach_availability_override (event_id)');
        $this->addSql('COMMENT ON COLUMN coach_availability_override.created_at IS \'(DC2Type:datetime_immutable)\'');
        // RESTRICT on both, like every other person-referencing key in this epic: an override is
        // part of the record FR-026 requires to survive, so a deletion anonymizes the account
        // rather than taking the trainer's explanation with it.
        $this->addSql('ALTER TABLE coach_availability_override ADD CONSTRAINT FK_65E461A63C105691 FOREIGN KEY (coach_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE coach_availability_override ADD CONSTRAINT FK_65E461A64594B015 FOREIGN KEY (overridden_by_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // Drops only what this migration created. The generated `CREATE SCHEMA public` was
        // removed for the reason given in Version20260822144052: the schema predates these
        // migrations, and recreating it fails wherever it still exists.
        $this->addSql('DROP SEQUENCE availability_slot_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE coach_availability_override_id_seq CASCADE');
        $this->addSql('ALTER TABLE coach_availability_override DROP CONSTRAINT FK_65E461A63C105691');
        $this->addSql('ALTER TABLE coach_availability_override DROP CONSTRAINT FK_65E461A64594B015');
        $this->addSql('DROP TABLE availability_slot');
        $this->addSql('DROP TABLE coach_availability_override');
    }
}
