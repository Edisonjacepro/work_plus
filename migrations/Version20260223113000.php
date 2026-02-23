<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill points claim approval ledger references and enforce non-null reference_id for claim approval entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE points_ledger_entry ple SET reference_id = pc.id FROM points_claim pc WHERE ple.reference_type = 'POINTS_CLAIM_APPROVAL' AND ple.reference_id IS NULL AND ple.idempotency_key = CONCAT('points_claim_approval_', pc.idempotency_key)");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM points_ledger_entry WHERE reference_type = 'POINTS_CLAIM_APPROVAL' AND reference_id IS NULL) THEN RAISE EXCEPTION 'Cannot enforce non-null reference_id for POINTS_CLAIM_APPROVAL: unresolved rows remain.'; END IF; END $$");
        $this->addSql("ALTER TABLE points_ledger_entry ADD CONSTRAINT chk_points_claim_approval_reference_id CHECK (reference_type <> 'POINTS_CLAIM_APPROVAL' OR reference_id IS NOT NULL)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE points_ledger_entry DROP CONSTRAINT chk_points_claim_approval_reference_id');
    }
}
