<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260410000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create initial schema: user, recording, recording_share, claude_session tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "user" (
            id UUID NOT NULL,
            email VARCHAR(180) NOT NULL,
            roles JSON NOT NULL,
            password VARCHAR(255) DEFAULT NULL,
            display_name VARCHAR(255) NOT NULL,
            encrypted_anthropic_api_key TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('COMMENT ON COLUMN "user".id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN "user".created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE recording (
            id UUID NOT NULL,
            owner_id UUID NOT NULL,
            title VARCHAR(255) NOT NULL,
            audio_file_path VARCHAR(512) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size_bytes INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            transcription TEXT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_BB532B177E3C61F9 ON recording (owner_id)');
        $this->addSql('COMMENT ON COLUMN recording.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN recording.owner_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN recording.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN recording.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE recording ADD CONSTRAINT FK_BB532B177E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE recording_share (
            id UUID NOT NULL,
            recording_id UUID NOT NULL,
            shared_with_id UUID NOT NULL,
            permission VARCHAR(10) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_SHARE_RECORDING ON recording_share (recording_id)');
        $this->addSql('CREATE INDEX IDX_SHARE_USER ON recording_share (shared_with_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SHARE_RECORDING_USER ON recording_share (recording_id, shared_with_id)');
        $this->addSql('COMMENT ON COLUMN recording_share.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN recording_share.recording_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN recording_share.shared_with_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN recording_share.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE recording_share ADD CONSTRAINT FK_SHARE_RECORDING FOREIGN KEY (recording_id) REFERENCES recording (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE recording_share ADD CONSTRAINT FK_SHARE_USER FOREIGN KEY (shared_with_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE claude_session (
            id UUID NOT NULL,
            recording_id UUID NOT NULL,
            user_id UUID NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_CLAUDE_RECORDING ON claude_session (recording_id)');
        $this->addSql('CREATE INDEX IDX_CLAUDE_USER ON claude_session (user_id)');
        $this->addSql('COMMENT ON COLUMN claude_session.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN claude_session.recording_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN claude_session.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN claude_session.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN claude_session.closed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE claude_session ADD CONSTRAINT FK_CLAUDE_RECORDING FOREIGN KEY (recording_id) REFERENCES recording (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE claude_session ADD CONSTRAINT FK_CLAUDE_USER FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Messenger transport table
        $this->addSql('CREATE TABLE messenger_messages (
            id BIGSERIAL NOT NULL,
            body TEXT NOT NULL,
            headers TEXT NOT NULL,
            queue_name VARCHAR(190) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
        $this->addSql('COMMENT ON COLUMN messenger_messages.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages.available_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages.delivered_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE OR REPLACE FUNCTION notify_messenger_messages() RETURNS TRIGGER AS $$
            BEGIN
                PERFORM pg_notify(\'messenger_messages\', NEW.queue_name::text);
                RETURN NEW;
            END;
        $$ LANGUAGE plpgsql');
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages');
        $this->addSql('CREATE TRIGGER notify_trigger AFTER INSERT OR UPDATE ON messenger_messages FOR EACH ROW EXECUTE PROCEDURE notify_messenger_messages()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE claude_session');
        $this->addSql('DROP TABLE recording_share');
        $this->addSql('DROP TABLE recording');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
