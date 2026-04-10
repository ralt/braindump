<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260410000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add PostgreSQL full-text search to recording table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recording ADD COLUMN search_vector tsvector');
        $this->addSql('CREATE INDEX idx_recording_search ON recording USING GIN(search_vector)');

        $this->addSql('
            CREATE OR REPLACE FUNCTION recording_search_update() RETURNS trigger AS $$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector(\'english\', coalesce(NEW.title, \'\')), \'A\') ||
                    setweight(to_tsvector(\'english\', coalesce(NEW.transcription, \'\')), \'B\');
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
        ');

        $this->addSql('
            CREATE TRIGGER trg_recording_search
                BEFORE INSERT OR UPDATE OF title, transcription ON recording
                FOR EACH ROW EXECUTE FUNCTION recording_search_update()
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_recording_search ON recording');
        $this->addSql('DROP FUNCTION IF EXISTS recording_search_update()');
        $this->addSql('DROP INDEX IF EXISTS idx_recording_search');
        $this->addSql('ALTER TABLE recording DROP COLUMN IF EXISTS search_vector');
    }
}
