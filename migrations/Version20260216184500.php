<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260216184500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add company profile fields for enterprise registration form';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD website VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE company ADD city VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE company ADD sector VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE company ADD company_size VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP website');
        $this->addSql('ALTER TABLE company DROP city');
        $this->addSql('ALTER TABLE company DROP sector');
        $this->addSql('ALTER TABLE company DROP company_size');
    }
}

