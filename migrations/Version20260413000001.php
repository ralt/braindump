<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename claude_session to ai_session, encrypted_anthropic_api_key to encrypted_ai_api_key, add ai_provider column';
    }

    public function up(Schema $schema): void
    {
        $isPostgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        if ($isPostgres) {
            $this->addSql('ALTER TABLE claude_session RENAME TO ai_session');
            $this->addSql('ALTER INDEX idx_claude_recording RENAME TO idx_ai_session_recording');
            $this->addSql('ALTER INDEX idx_claude_user RENAME TO idx_ai_session_user');
            $this->addSql('ALTER TABLE "user" RENAME COLUMN encrypted_anthropic_api_key TO encrypted_ai_api_key');
            $this->addSql('ALTER TABLE "user" ADD ai_provider VARCHAR(50) DEFAULT NULL');
        } else {
            // SQLite: recreate tables (no ALTER TABLE RENAME COLUMN support pre-3.25)
            $this->addSql('ALTER TABLE claude_session RENAME TO ai_session');
            $this->addSql('ALTER TABLE "user" ADD ai_provider VARCHAR(50) DEFAULT NULL');
            // SQLite 3.25+ supports RENAME COLUMN
            $this->addSql('ALTER TABLE "user" RENAME COLUMN encrypted_anthropic_api_key TO encrypted_ai_api_key');
        }
    }

    public function down(Schema $schema): void
    {
        $isPostgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        if ($isPostgres) {
            $this->addSql('ALTER TABLE ai_session RENAME TO claude_session');
            $this->addSql('ALTER INDEX idx_ai_session_recording RENAME TO idx_claude_recording');
            $this->addSql('ALTER INDEX idx_ai_session_user RENAME TO idx_claude_user');
            $this->addSql('ALTER TABLE "user" RENAME COLUMN encrypted_ai_api_key TO encrypted_anthropic_api_key');
            $this->addSql('ALTER TABLE "user" DROP COLUMN ai_provider');
        } else {
            $this->addSql('ALTER TABLE ai_session RENAME TO claude_session');
            $this->addSql('ALTER TABLE "user" RENAME COLUMN encrypted_ai_api_key TO encrypted_anthropic_api_key');
            $this->addSql('ALTER TABLE "user" DROP COLUMN ai_provider');
        }
    }
}
