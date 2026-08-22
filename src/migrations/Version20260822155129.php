<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TASK-004 — the profile, family and branding schema (FR-060 … FR-072).
 *
 * Additive throughout: five new tables, new nullable columns on two existing ones, and no
 * change to the type or nullability of any column that already holds data. It is therefore
 * safe to run against a populated database with no backfill and no window.
 *
 * The constraints worth reading are the unique ones, because each is a business rule the
 * application would otherwise have to defend alone:
 *
 *  - `UNIQ_COACH_PROFILE_USER_ORG` — one bio per (coach, organization). A coach who has worked
 *    for two trainers has two profiles, and this is what keeps `findOneFor()` a lookup rather
 *    than a guess between duplicate rows.
 *  - `UNIQ_TRAINER_PROFILE_ORG` and `UNIQ_ORGANIZATION_BRANDING_ORG` — one of each per tenant,
 *    which is BR-069 for branding and the "one trainer owns one organization" invariant D3
 *    established for the trainer profile.
 *  - `UNIQ_USER_LOGIN_USERNAME` — the child-login usernames of G-23. Two parents naming their
 *    children the same thing at the same moment both pass the service's availability check;
 *    this index is what makes the second one lose the race instead of creating a second
 *    account with a colliding derived email address.
 *
 * `admin_preferences` and `organization_branding` cascade on delete while every other foreign
 * key restricts. That asymmetry is deliberate: those two hold a *setting*, and a setting has no
 * meaning once the row it configures is gone, whereas a profile or an emergency contact is part
 * of the history FR-026 requires to survive — an erasure anonymizes those rows in place rather
 * than removing them.
 */
final class Version20260822155129 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TASK-004: create coach_profile, trainer_profile, emergency_contact, organization_branding and admin_preferences; add profile fields, photo paths and child-login usernames';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE admin_preferences_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE coach_profile_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE emergency_contact_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE organization_branding_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE trainer_profile_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE admin_preferences (id INT NOT NULL, user_id INT NOT NULL, notify_on_trainer_created BOOLEAN DEFAULT true NOT NULL, notify_on_account_erasure BOOLEAN DEFAULT true NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ADMIN_PREFERENCES_USER ON admin_preferences (user_id)');
        $this->addSql('COMMENT ON COLUMN admin_preferences.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE coach_profile (id INT NOT NULL, user_id INT NOT NULL, organization_id INT NOT NULL, bio TEXT DEFAULT NULL, credentials TEXT DEFAULT NULL, certifications TEXT DEFAULT NULL, public BOOLEAN DEFAULT false NOT NULL, joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_3A874247A76ED395 ON coach_profile (user_id)');
        $this->addSql('CREATE INDEX IDX_3A87424732C8A3DE ON coach_profile (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_COACH_PROFILE_USER_ORG ON coach_profile (user_id, organization_id)');
        $this->addSql('COMMENT ON COLUMN coach_profile.joined_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN coach_profile.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE emergency_contact (id INT NOT NULL, parent_id INT NOT NULL, name VARCHAR(255) NOT NULL, relationship VARCHAR(64) NOT NULL, phone VARCHAR(32) NOT NULL, display_order INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_FE1C6190727ACA70 ON emergency_contact (parent_id)');
        $this->addSql('CREATE INDEX IDX_EMERGENCY_CONTACT_PARENT ON emergency_contact (parent_id, display_order)');
        $this->addSql('COMMENT ON COLUMN emergency_contact.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN emergency_contact.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE organization_branding (id INT NOT NULL, organization_id INT NOT NULL, logo_path VARCHAR(255) DEFAULT NULL, primary_color_hex VARCHAR(7) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ORGANIZATION_BRANDING_ORG ON organization_branding (organization_id)');
        $this->addSql('COMMENT ON COLUMN organization_branding.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE trainer_profile (id INT NOT NULL, user_id INT NOT NULL, organization_id INT NOT NULL, business_name VARCHAR(255) DEFAULT NULL, address TEXT DEFAULT NULL, website VARCHAR(255) DEFAULT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_4D6893C0A76ED395 ON trainer_profile (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_TRAINER_PROFILE_ORG ON trainer_profile (organization_id)');
        $this->addSql('COMMENT ON COLUMN trainer_profile.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN trainer_profile.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE admin_preferences ADD CONSTRAINT FK_742BEFFCA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE coach_profile ADD CONSTRAINT FK_3A874247A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE coach_profile ADD CONSTRAINT FK_3A87424732C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE emergency_contact ADD CONSTRAINT FK_FE1C6190727ACA70 FOREIGN KEY (parent_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE organization_branding ADD CONSTRAINT FK_5D434CF532C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trainer_profile ADD CONSTRAINT FK_4D6893C0A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE trainer_profile ADD CONSTRAINT FK_4D6893C032C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        // FR-061's player fields and FR-062's photo, completing the table TASK-003 seeded with
        // only what the invitation flow wrote. All nullable: FR-063 makes name, age and gender
        // the only required fields, and an existing row is valid without any of these.
        $this->addSql('ALTER TABLE player_profile ADD school VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE player_profile ADD jersey_number VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE player_profile ADD photo_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE player_profile ADD photo_thumbnail_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE player_profile ADD skill_level VARCHAR(32) DEFAULT NULL');
        // `login_username` is the child-login identifier of G-23 — null for every ordinary
        // account, which signs in with its email address. The photo columns hold a path
        // relative to the private upload root, never a URL (NFR-066).
        $this->addSql('ALTER TABLE "user" ADD login_username VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD photo_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD photo_thumbnail_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_LOGIN_USERNAME ON "user" (login_username)');
    }

    public function down(Schema $schema): void
    {
        // Reverting drops only what this migration created. The generated
        // `CREATE SCHEMA public` was removed for the reason given in Version20260822144052:
        // the schema predates both migrations, and recreating it fails wherever it still exists.
        $this->addSql('DROP SEQUENCE admin_preferences_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE coach_profile_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE emergency_contact_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE organization_branding_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE trainer_profile_id_seq CASCADE');
        $this->addSql('ALTER TABLE admin_preferences DROP CONSTRAINT FK_742BEFFCA76ED395');
        $this->addSql('ALTER TABLE coach_profile DROP CONSTRAINT FK_3A874247A76ED395');
        $this->addSql('ALTER TABLE coach_profile DROP CONSTRAINT FK_3A87424732C8A3DE');
        $this->addSql('ALTER TABLE emergency_contact DROP CONSTRAINT FK_FE1C6190727ACA70');
        $this->addSql('ALTER TABLE organization_branding DROP CONSTRAINT FK_5D434CF532C8A3DE');
        $this->addSql('ALTER TABLE trainer_profile DROP CONSTRAINT FK_4D6893C0A76ED395');
        $this->addSql('ALTER TABLE trainer_profile DROP CONSTRAINT FK_4D6893C032C8A3DE');
        $this->addSql('DROP TABLE admin_preferences');
        $this->addSql('DROP TABLE coach_profile');
        $this->addSql('DROP TABLE emergency_contact');
        $this->addSql('DROP TABLE organization_branding');
        $this->addSql('DROP TABLE trainer_profile');
        $this->addSql('DROP INDEX UNIQ_USER_LOGIN_USERNAME');
        $this->addSql('ALTER TABLE "user" DROP login_username');
        $this->addSql('ALTER TABLE "user" DROP photo_path');
        $this->addSql('ALTER TABLE "user" DROP photo_thumbnail_path');
        $this->addSql('ALTER TABLE player_profile DROP school');
        $this->addSql('ALTER TABLE player_profile DROP jersey_number');
        $this->addSql('ALTER TABLE player_profile DROP photo_path');
        $this->addSql('ALTER TABLE player_profile DROP photo_thumbnail_path');
        $this->addSql('ALTER TABLE player_profile DROP skill_level');
    }
}
