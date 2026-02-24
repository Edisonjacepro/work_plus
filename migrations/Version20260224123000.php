<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260224123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce one credit ledger entry per approved points claim reference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM points_ledger_entry WHERE reference_type = 'POINTS_CLAIM_APPROVAL' AND entry_type = 'CREDIT' AND reference_id IS NOT NULL GROUP BY reference_id HAVING COUNT(*) > 1) THEN RAISE EXCEPTION 'Cannot enforce unique points claim approval credits: duplicate reference_id rows exist.'; END IF; END $$");
        $this->addSql("CREATE UNIQUE INDEX uniq_points_ledger_claim_approval_credit ON points_ledger_entry (reference_id) WHERE reference_type = 'POINTS_CLAIM_APPROVAL' AND entry_type = 'CREDIT' AND reference_id IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_points_ledger_claim_approval_credit');
    }
}
