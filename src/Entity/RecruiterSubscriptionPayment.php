<?php

namespace App\Entity;

use App\Repository\RecruiterSubscriptionPaymentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecruiterSubscriptionPaymentRepository::class)]
#[ORM\Table(name: 'recruiter_subscription_payment')]
#[ORM\UniqueConstraint(name: 'uniq_recruiter_subscription_payment_idempotency', fields: ['idempotencyKey'])]
#[ORM\HasLifecycleCallbacks]
class RecruiterSubscriptionPayment
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_SUCCEEDED = 'SUCCEEDED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_CANCELED = 'CANCELED';

    public const PROVIDER_FAKE = 'fake';
    public const PROVIDER_STRIPE = 'stripe';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Company $company = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $initiatedBy = null;

    #[ORM\Column(length: 20)]
    private string $planCode = Company::RECRUITER_PLAN_STARTER;

    #[ORM\Column]
    private int $amountCents = 0;

    #[ORM\Column(length: 3)]
    private string $currencyCode = 'EUR';

    #[ORM\Column(length: 40)]
    private string $provider = self::PROVIDER_FAKE;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $providerSessionId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $providerPaymentId = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 120)]
    private string $idempotencyKey = '';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $providerPayload = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $periodStart = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $periodEnd = null;

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

    public function getInitiatedBy(): ?User
    {
        return $this->initiatedBy;
    }

    public function setInitiatedBy(?User $initiatedBy): static
    {
        $this->initiatedBy = $initiatedBy;

        return $this;
    }

    public function getPlanCode(): string
    {
        return $this->planCode;
    }

    public function setPlanCode(string $planCode): static
    {
        $this->planCode = $planCode;

        return $this;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function setAmountCents(int $amountCents): static
    {
        $this->amountCents = $amountCents;

        return $this;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(string $currencyCode): static
    {
        $this->currencyCode = strtoupper($currencyCode);

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = strtolower($provider);

        return $this;
    }

    public function getProviderSessionId(): ?string
    {
        return $this->providerSessionId;
    }

    public function setProviderSessionId(?string $providerSessionId): static
    {
        $this->providerSessionId = $providerSessionId;

        return $this;
    }

    public function getProviderPaymentId(): ?string
    {
        return $this->providerPaymentId;
    }

    public function setProviderPaymentId(?string $providerPaymentId): static
    {
        $this->providerPaymentId = $providerPaymentId;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = strtoupper($status);

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

    public function getProviderPayload(): ?array
    {
        return $this->providerPayload;
    }

    public function setProviderPayload(?array $providerPayload): static
    {
        $this->providerPayload = $providerPayload;

        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getPeriodStart(): ?\DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(\DateTimeImmutable $periodStart): static
    {
        $this->periodStart = $periodStart;

        return $this;
    }

    public function getPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function setPeriodEnd(\DateTimeImmutable $periodEnd): static
    {
        $this->periodEnd = $periodEnd;

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

    public function isSucceeded(): bool
    {
        return self::STATUS_SUCCEEDED === $this->status;
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

