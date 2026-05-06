<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace pi.dev terminal sessions with chat: drop ai_session.status/closed_at, add title, create ai_message table';
    }

    public function up(Schema $schema): void
    {
        $isPostgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        // Wipe pi.dev-era session shells (no messages were stored, nothing to preserve)
        $this->addSql('DELETE FROM ai_session');

        $this->addSql('ALTER TABLE ai_session DROP COLUMN status');
        $this->addSql('ALTER TABLE ai_session DROP COLUMN closed_at');
        $this->addSql('ALTER TABLE ai_session ADD title VARCHAR(255) DEFAULT NULL');

        if ($isPostgres) {
            $this->addSql('CREATE TABLE ai_message (
                id UUID NOT NULL,
                session_id UUID NOT NULL,
                role VARCHAR(20) NOT NULL,
                content TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )');
            $this->addSql('CREATE INDEX idx_ai_message_session_created ON ai_message (session_id, created_at)');
            $this->addSql('ALTER TABLE ai_message ADD CONSTRAINT fk_ai_message_session FOREIGN KEY (session_id) REFERENCES ai_session (id) ON DELETE CASCADE');
        } else {
            $this->addSql('CREATE TABLE ai_message (
                id VARCHAR(36) NOT NULL,
                session_id VARCHAR(36) NOT NULL,
                role VARCHAR(20) NOT NULL,
                content TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY(id),
                FOREIGN KEY(session_id) REFERENCES ai_session(id) ON DELETE CASCADE
            )');
            $this->addSql('CREATE INDEX idx_ai_message_session_created ON ai_message (session_id, created_at)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_message');
        $this->addSql('ALTER TABLE ai_session DROP COLUMN title');
        $this->addSql('ALTER TABLE ai_session ADD status VARCHAR(20) NOT NULL DEFAULT \'closed\'');
        $this->addSql('ALTER TABLE ai_session ADD closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }
}
