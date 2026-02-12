<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260212134500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique index on company name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_company_name ON company (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_company_name');
    }
}
