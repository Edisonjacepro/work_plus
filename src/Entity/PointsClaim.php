<?php

namespace App\Entity;

use App\Repository\PointsClaimRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PointsClaimRepository::class)]
#[ORM\Table(name: 'points_claim')]
#[ORM\UniqueConstraint(name: 'uniq_points_claim_idempotency_key', fields: ['idempotencyKey'])]
#[ORM\Index(name: 'idx_points_claim_company_status', columns: ['company_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
class PointsClaim
{
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_IN_REVIEW = 'IN_REVIEW';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    public const CLAIM_TYPE_TRAINING = 'TRAINING';
    public const CLAIM_TYPE_VOLUNTEERING = 'VOLUNTEERING';
    public const CLAIM_TYPE_CERTIFICATION = 'CERTIFICATION';
    public const CLAIM_TYPE_OTHER = 'OTHER';

    public const RULE_VERSION_V1 = 'points_claim_v1_2026_02';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Company $company = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Offer $offer = null;

    #[ORM\Column(length: 40)]
    #[Assert\Choice(choices: [
        self::CLAIM_TYPE_TRAINING,
        self::CLAIM_TYPE_VOLUNTEERING,
        self::CLAIM_TYPE_CERTIFICATION,
        self::CLAIM_TYPE_OTHER,
    ])]
    private string $claimType = self::CLAIM_TYPE_OTHER;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [
        self::STATUS_SUBMITTED,
        self::STATUS_IN_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ])]
    private string $status = self::STATUS_SUBMITTED;

    #[ORM\Column]
    private int $requestedPoints = 0;

    #[ORM\Column(nullable: true)]
    private ?int $approvedPoints = null;

    #[ORM\Column(type: 'json')]
    private array $evidenceDocuments = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $externalChecks = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $evidenceScore = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $decisionReason = null;

    #[ORM\Column(length: 40)]
    private string $ruleVersion = self::RULE_VERSION_V1;

    #[ORM\Column(length: 120)]
    private string $idempotencyKey = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

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

    public function getOffer(): ?Offer
    {
        return $this->offer;
    }

    public function setOffer(?Offer $offer): static
    {
        $this->offer = $offer;

        return $this;
    }

    public function getClaimType(): string
    {
        return $this->claimType;
    }

    public function setClaimType(string $claimType): static
    {
        $this->claimType = $claimType;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRequestedPoints(): int
    {
        return $this->requestedPoints;
    }

    public function setRequestedPoints(int $requestedPoints): static
    {
        $this->requestedPoints = $requestedPoints;

        return $this;
    }

    public function getApprovedPoints(): ?int
    {
        return $this->approvedPoints;
    }

    public function setApprovedPoints(?int $approvedPoints): static
    {
        $this->approvedPoints = $approvedPoints;

        return $this;
    }

    public function getEvidenceDocuments(): array
    {
        return $this->evidenceDocuments;
    }

    public function setEvidenceDocuments(array $evidenceDocuments): static
    {
        $this->evidenceDocuments = $evidenceDocuments;

        return $this;
    }

    public function getExternalChecks(): ?array
    {
        return $this->externalChecks;
    }

    public function setExternalChecks(?array $externalChecks): static
    {
        $this->externalChecks = $externalChecks;

        return $this;
    }

    public function getEvidenceScore(): int
    {
        return $this->evidenceScore;
    }

    public function setEvidenceScore(int $evidenceScore): static
    {
        $this->evidenceScore = $evidenceScore;

        return $this;
    }

    public function getDecisionReason(): ?string
    {
        return $this->decisionReason;
    }

    public function setDecisionReason(?string $decisionReason): static
    {
        $this->decisionReason = $decisionReason;

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

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?User $reviewedBy): static
    {
        $this->reviewedBy = $reviewedBy;

        return $this;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?\DateTimeImmutable $reviewedAt): static
    {
        $this->reviewedAt = $reviewedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
