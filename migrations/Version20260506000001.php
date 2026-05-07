<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add skill table for per-user named instruction snippets used in AI chats';
    }

    public function up(Schema $schema): void
    {
        $isPostgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        if ($isPostgres) {
            $this->addSql('CREATE TABLE skill (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                name VARCHAR(100) NOT NULL,
                instructions TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )');
        } else {
            $this->addSql('CREATE TABLE skill (
                id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                name VARCHAR(100) NOT NULL,
                instructions TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY(id),
                FOREIGN KEY(user_id) REFERENCES "user"(id) ON DELETE CASCADE
            )');
        }

        $this->addSql('CREATE UNIQUE INDEX uniq_skill_user_name ON skill (user_id, name)');
        $this->addSql('CREATE INDEX idx_skill_user ON skill (user_id)');

        if ($isPostgres) {
            $this->addSql('ALTER TABLE skill ADD CONSTRAINT fk_skill_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE skill');
    }
}
