<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260414000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add oidc_subject column to user table for OIDC authentication';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD oidc_subject VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649_OIDC_SUBJECT ON "user" (oidc_subject)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D649_OIDC_SUBJECT');
        $this->addSql('ALTER TABLE "user" DROP oidc_subject');
    }
}
