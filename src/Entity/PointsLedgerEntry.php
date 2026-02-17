<?php

namespace App\Entity;

use App\Repository\PointsLedgerEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PointsLedgerEntryRepository::class)]
#[ORM\Table(name: 'points_ledger_entry')]
#[ORM\UniqueConstraint(name: 'uniq_points_ledger_entry_idempotency_key', fields: ['idempotencyKey'])]
#[ORM\HasLifecycleCallbacks]
class PointsLedgerEntry
{
    public const TYPE_CREDIT = 'CREDIT';
    public const TYPE_DEBIT = 'DEBIT';
    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    public const REFERENCE_OFFER_PUBLICATION = 'OFFER_PUBLICATION';
    public const REFERENCE_APPLICATION_SUBMISSION = 'APPLICATION_SUBMISSION';
    public const REFERENCE_APPLICATION_HIRED = 'APPLICATION_HIRED';
    public const REFERENCE_POINTS_CLAIM_APPROVAL = 'POINTS_CLAIM_APPROVAL';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::TYPE_CREDIT, self::TYPE_DEBIT, self::TYPE_ADJUSTMENT])]
    private string $entryType = self::TYPE_CREDIT;

    #[ORM\Column]
    private int $points = 0;

    #[ORM\Column(length: 255)]
    private string $reason = '';

    #[ORM\Column(length: 40)]
    private string $referenceType = self::REFERENCE_OFFER_PUBLICATION;

    #[ORM\Column(nullable: true)]
    private ?int $referenceId = null;

    #[ORM\Column(length: 40)]
    private string $ruleVersion = ImpactScore::RULE_VERSION_V1_AUTO;

    #[ORM\Column(length: 120)]
    private string $idempotencyKey = '';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntryType(): string
    {
        return $this->entryType;
    }

    public function setEntryType(string $entryType): static
    {
        $this->entryType = $entryType;

        return $this;
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function setPoints(int $points): static
    {
        $this->points = $points;

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getReferenceType(): string
    {
        return $this->referenceType;
    }

    public function setReferenceType(string $referenceType): static
    {
        $this->referenceType = $referenceType;

        return $this;
    }

    public function getReferenceId(): ?int
    {
        return $this->referenceId;
    }

    public function setReferenceId(?int $referenceId): static
    {
        $this->referenceId = $referenceId;

        return $this;
    }

    public function getRuleVersion(): string
    {
        return $this->ruleVersion;
    }

    public function setRuleVersion(string $ruleVersion): static
    {
        $this->ruleVersion = $ruleVersion;

        return $this;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(string $idempotencyKey): static
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
