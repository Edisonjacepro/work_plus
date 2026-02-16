<?php

namespace App\Entity;

use App\Repository\ImpactScoreRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImpactScoreRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ImpactScore
{
    public const RULE_VERSION_V1_AUTO = 'v1_auto_2026_02';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Offer $offer = null;

    #[ORM\Column]
    private int $societyScore = 0;

    #[ORM\Column]
    private int $biodiversityScore = 0;

    #[ORM\Column]
    private int $ghgScore = 0;

    #[ORM\Column]
    private int $totalScore = 0;

    #[ORM\Column(type: 'float')]
    private float $confidence = 0.0;

    #[ORM\Column(length: 40)]
    private string $ruleVersion = self::RULE_VERSION_V1_AUTO;

    #[ORM\Column(type: 'json')]
    private array $evidence = [];

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isAutomated = true;

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

    public function getSocietyScore(): int
    {
        return $this->societyScore;
    }

    public function setSocietyScore(int $societyScore): static
    {
        $this->societyScore = $societyScore;

        return $this;
    }

    public function getBiodiversityScore(): int
    {
        return $this->biodiversityScore;
    }

    public function setBiodiversityScore(int $biodiversityScore): static
    {
        $this->biodiversityScore = $biodiversityScore;

        return $this;
    }

    public function getGhgScore(): int
    {
        return $this->ghgScore;
    }

    public function setGhgScore(int $ghgScore): static
    {
        $this->ghgScore = $ghgScore;

        return $this;
    }

    public function getTotalScore(): int
    {
        return $this->totalScore;
    }

    public function setTotalScore(int $totalScore): static
    {
        $this->totalScore = $totalScore;

        return $this;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function setConfidence(float $confidence): static
    {
        $this->confidence = $confidence;

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

    public function getEvidence(): array
    {
        return $this->evidence;
    }

    public function setEvidence(array $evidence): static
    {
        $this->evidence = $evidence;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isAutomated(): bool
    {
        return $this->isAutomated;
    }

    public function setIsAutomated(bool $isAutomated): static
    {
        $this->isAutomated = $isAutomated;

        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
