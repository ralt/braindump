<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop recording_share table — sharing feature removed in favor of strictly per-user recordings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS recording_share');
    }

    public function down(Schema $schema): void
    {
        $isPostgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        if ($isPostgres) {
            $this->addSql('CREATE TABLE recording_share (
                id UUID NOT NULL,
                recording_id UUID NOT NULL,
                shared_with_id UUID NOT NULL,
                permission VARCHAR(10) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )');
            $this->addSql('ALTER TABLE recording_share ADD CONSTRAINT FK_SHARE_RECORDING FOREIGN KEY (recording_id) REFERENCES recording (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE recording_share ADD CONSTRAINT FK_SHARE_USER FOREIGN KEY (shared_with_id) REFERENCES "user" (id) ON DELETE CASCADE');
        } else {
            $this->addSql('CREATE TABLE recording_share (
                id VARCHAR(36) NOT NULL,
                recording_id VARCHAR(36) NOT NULL,
                shared_with_id VARCHAR(36) NOT NULL,
                permission VARCHAR(10) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY(id),
                FOREIGN KEY (recording_id) REFERENCES recording (id),
                FOREIGN KEY (shared_with_id) REFERENCES "user" (id)
            )');
        }
        $this->addSql('CREATE INDEX IDX_SHARE_RECORDING ON recording_share (recording_id)');
        $this->addSql('CREATE INDEX IDX_SHARE_USER ON recording_share (shared_with_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SHARE_RECORDING_USER ON recording_share (recording_id, shared_with_id)');
    }
}
