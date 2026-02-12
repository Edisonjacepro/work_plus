<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260212122859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" ADD account_type VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD first_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD last_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ALTER company_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" DROP account_type');
        $this->addSql('ALTER TABLE "user" DROP first_name');
        $this->addSql('ALTER TABLE "user" DROP last_name');
        $this->addSql('ALTER TABLE "user" ALTER company_id SET NOT NULL');
    }
}
