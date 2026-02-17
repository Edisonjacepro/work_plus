<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260217223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add application hiring status and hired timestamp';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE application ADD status VARCHAR(20) DEFAULT 'SUBMITTED' NOT NULL");
        $this->addSql('ALTER TABLE application ADD hired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE application SET status = 'SUBMITTED' WHERE status IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE application DROP status');
        $this->addSql('ALTER TABLE application DROP hired_at');
    }
}
