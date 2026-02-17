<?php

namespace App\Entity;

use App\Repository\PointsClaimReviewEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PointsClaimReviewEventRepository::class)]
#[ORM\Table(name: 'points_claim_review_event')]
#[ORM\Index(name: 'idx_points_claim_review_event_claim', columns: ['points_claim_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class PointsClaimReviewEvent
{
    public const ACTION_SUBMITTED = 'SUBMITTED';
    public const ACTION_AUTO_APPROVED = 'AUTO_APPROVED';
    public const ACTION_AUTO_REJECTED = 'AUTO_REJECTED';
    public const ACTION_MARKED_IN_REVIEW = 'MARKED_IN_REVIEW';
    public const ACTION_APPROVED = 'APPROVED';
    public const ACTION_REJECTED = 'REJECTED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PointsClaim $pointsClaim = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(length: 40)]
    private string $action = self::ACTION_SUBMITTED;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $reasonCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reasonText = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPointsClaim(): ?PointsClaim
    {
        return $this->pointsClaim;
    }

    public function setPointsClaim(?PointsClaim $pointsClaim): static
    {
        $this->pointsClaim = $pointsClaim;

        return $this;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function setActor(?User $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getReasonCode(): ?string
    {
        return $this->reasonCode;
    }

    public function setReasonCode(?string $reasonCode): static
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
