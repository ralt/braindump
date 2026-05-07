<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ai_session_skill join table — skills activated for a chat session augment its system prompt';
    }

    public function up(Schema $schema): void
    {
        $isPostgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        if ($isPostgres) {
            $this->addSql('CREATE TABLE ai_session_skill (
                ai_session_id UUID NOT NULL,
                skill_id UUID NOT NULL,
                PRIMARY KEY(ai_session_id, skill_id)
            )');
            $this->addSql('CREATE INDEX idx_ai_session_skill_session ON ai_session_skill (ai_session_id)');
            $this->addSql('CREATE INDEX idx_ai_session_skill_skill ON ai_session_skill (skill_id)');
            $this->addSql('ALTER TABLE ai_session_skill ADD CONSTRAINT fk_ai_session_skill_session FOREIGN KEY (ai_session_id) REFERENCES ai_session (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE ai_session_skill ADD CONSTRAINT fk_ai_session_skill_skill FOREIGN KEY (skill_id) REFERENCES skill (id) ON DELETE CASCADE');
        } else {
            $this->addSql('CREATE TABLE ai_session_skill (
                ai_session_id VARCHAR(36) NOT NULL,
                skill_id VARCHAR(36) NOT NULL,
                PRIMARY KEY(ai_session_id, skill_id),
                FOREIGN KEY(ai_session_id) REFERENCES ai_session(id) ON DELETE CASCADE,
                FOREIGN KEY(skill_id) REFERENCES skill(id) ON DELETE CASCADE
            )');
            $this->addSql('CREATE INDEX idx_ai_session_skill_session ON ai_session_skill (ai_session_id)');
            $this->addSql('CREATE INDEX idx_ai_session_skill_skill ON ai_session_skill (skill_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_session_skill');
    }
}
