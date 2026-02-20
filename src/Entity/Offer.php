<?php

namespace App\Entity;

use App\Repository\OfferRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OfferRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Offer
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PUBLISHED = 'PUBLISHED';

    public const MODERATION_STATUS_DRAFT = 'DRAFT';
    public const MODERATION_STATUS_SUBMITTED = 'SUBMITTED';
    public const MODERATION_STATUS_APPROVED = 'APPROVED';
    public const MODERATION_STATUS_REJECTED = 'REJECTED';

    public const MODERATION_RULE_VERSION_V1 = 'offer_moderation_v1_2026_02';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $title = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
    ])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: 'json')]
    #[Assert\Count(min: 1, minMessage: 'Veuillez sélectionner au moins une catégorie d’impact.')]
    private array $impactCategories = [];

    #[ORM\ManyToOne(inversedBy: 'offers')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Company $company = null;

    #[ORM\ManyToOne(inversedBy: 'offers')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?User $author = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isVisible = true;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [
        self::MODERATION_STATUS_DRAFT,
        self::MODERATION_STATUS_SUBMITTED,
        self::MODERATION_STATUS_APPROVED,
        self::MODERATION_STATUS_REJECTED,
    ])]
    private string $moderationStatus = self::MODERATION_STATUS_DRAFT;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $moderationReasonCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $moderationReason = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $moderationScore = 0;

    #[ORM\Column(length: 40)]
    private string $moderationRuleVersion = self::MODERATION_RULE_VERSION_V1;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $moderatedAt = null;

    public function __construct()
    {
        $this->status = self::STATUS_DRAFT;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

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

    public function getImpactCategories(): array
    {
        return $this->impactCategories;
    }

    public function setImpactCategories(array $impactCategories): static
    {
        $this->impactCategories = $impactCategories;

        return $this;
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

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

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

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->isVisible;
    }

    public function setIsVisible(bool $isVisible): static
    {
        $this->isVisible = $isVisible;

        return $this;
    }

    public function getModerationStatus(): string
    {
        return $this->moderationStatus;
    }

    public function setModerationStatus(string $moderationStatus): static
    {
        $this->moderationStatus = $moderationStatus;

        return $this;
    }

    public function getModerationReasonCode(): ?string
    {
        return $this->moderationReasonCode;
    }

    public function setModerationReasonCode(?string $moderationReasonCode): static
    {
        $this->moderationReasonCode = $moderationReasonCode;

        return $this;
    }

    public function getModerationReason(): ?string
    {
        return $this->moderationReason;
    }

    public function setModerationReason(?string $moderationReason): static
    {
        $this->moderationReason = $moderationReason;

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

    public function getModerationRuleVersion(): string
    {
        return $this->moderationRuleVersion;
    }

    public function setModerationRuleVersion(string $moderationRuleVersion): static
    {
        $this->moderationRuleVersion = $moderationRuleVersion;

        return $this;
    }

    public function getModeratedAt(): ?\DateTimeImmutable
    {
        return $this->moderatedAt;
    }

    public function setModeratedAt(?\DateTimeImmutable $moderatedAt): static
    {
        $this->moderatedAt = $moderatedAt;

        return $this;
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
