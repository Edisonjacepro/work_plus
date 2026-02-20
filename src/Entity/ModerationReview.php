<?php

namespace App\Entity;

use App\Repository\ModerationReviewRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ModerationReviewRepository::class)]
#[ORM\Table(name: 'moderation_review')]
#[ORM\Index(name: 'idx_moderation_review_offer_created_at', columns: ['offer_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class ModerationReview
{
    public const ACTION_SUBMITTED = 'SUBMITTED';
    public const ACTION_AUTO_APPROVED = 'AUTO_APPROVED';
    public const ACTION_AUTO_REJECTED = 'AUTO_REJECTED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Offer $offer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(length: 40)]
    private string $action = self::ACTION_SUBMITTED;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $reasonCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reasonText = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $moderationScore = 0;

    #[ORM\Column(length: 40)]
    private string $ruleVersion = Offer::MODERATION_RULE_VERSION_V1;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getModerationScore(): int
    {
        return $this->moderationScore;
    }

    public function setModerationScore(int $moderationScore): static
    {
        $this->moderationScore = $moderationScore;

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
