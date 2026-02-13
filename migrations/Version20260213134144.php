<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Placeholder migration to keep local migration history consistent.
 */
final class Version20260213134144 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-op placeholder for previously executed local migration.';
    }

    public function up(Schema $schema): void
    {
        // No-op.
    }

    public function down(Schema $schema): void
    {
        // No-op.
    }
}
