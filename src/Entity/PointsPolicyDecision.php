<?php

namespace App\Entity;

use App\Repository\PointsPolicyDecisionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PointsPolicyDecisionRepository::class)]
#[ORM\Table(name: 'points_policy_decision')]
#[ORM\Index(name: 'idx_points_policy_decision_company_created_at', columns: ['company_id', 'created_at'])]
#[ORM\Index(name: 'idx_points_policy_decision_user_created_at', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'idx_points_policy_decision_reference', columns: ['reference_type', 'reference_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class PointsPolicyDecision
{
    public const RULE_VERSION_V1 = 'points_policy_v1_2026_02';
    public const STATUS_ALLOW = 'ALLOW';
    public const STATUS_BLOCK = 'BLOCK';
    public const REASON_CODE_ALLOWED = 'POLICY_ALLOWED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 10)]
    private string $decisionStatus = self::STATUS_ALLOW;

    #[ORM\Column(length: 80)]
    private string $reasonCode = self::REASON_CODE_ALLOWED;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reasonText = null;

    #[ORM\Column(length: 40)]
    private string $referenceType = PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION;

    #[ORM\Column(nullable: true)]
    private ?int $referenceId = null;

    #[ORM\Column]
    private int $points = 0;

    #[ORM\Column(length: 40)]
    private string $ruleVersion = self::RULE_VERSION_V1;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDecisionStatus(): string
    {
        return $this->decisionStatus;
    }

    public function setDecisionStatus(string $decisionStatus): static
    {
        $this->decisionStatus = $decisionStatus;

        return $this;
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
    }

    public function setReasonCode(string $reasonCode): static
    {
        $this->reasonCode = $reasonCode;

        return $this;
    }

    public function getReasonText(): ?string
    {
        return $this->reasonText;
    }

    public function setReasonText(?string $reasonText): static
    {
        $this->reasonText = $reasonText;

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

    public function getPoints(): int
    {
        return $this->points;
    }

    public function setPoints(int $points): static
    {
        $this->points = $points;

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

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
