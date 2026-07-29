<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recording.duration_seconds — the audio is deleted once transcribed, so length has to be captured at upload';
    }

    public function up(Schema $schema): void
    {
        // Nullable on purpose: existing recordings have already lost their audio, so there is
        // nothing left to measure and no honest value to backfill.
        $this->addSql('ALTER TABLE recording ADD duration_seconds INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recording DROP COLUMN duration_seconds');
    }
}
