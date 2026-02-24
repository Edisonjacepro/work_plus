<?php

namespace App\Service;

use App\Entity\Company;
use App\Repository\PointsPolicyDecisionRepository;

class PointsPolicyRiskService
{
    public function __construct(
        private readonly PointsPolicyDecisionRepository $pointsPolicyDecisionRepository,
        array $pointsPolicyCooldown,
    ) {
        $threshold = (int) ($pointsPolicyCooldown['threshold_24h_blocks'] ?? 0);
        $durationMinutes = (int) ($pointsPolicyCooldown['duration_minutes'] ?? 0);
        if ($threshold <= 0 || $durationMinutes <= 0) {
            throw new \InvalidArgumentException('Invalid points policy cooldown configuration.');
        }

        $this->pointsPolicyCooldownThreshold24h = $threshold;
        $this->pointsPolicyCooldownDurationMinutes = $durationMinutes;
    }

    private int $pointsPolicyCooldownThreshold24h;
    private int $pointsPolicyCooldownDurationMinutes;

    /**
     * @return array{
     *     blocked24h: int,
     *     blocked7d: int,
     *     cooldownActive: bool,
     *     cooldownUntil: ?\DateTimeImmutable,
     *     threshold24h: int,
     *     durationMinutes: int
     * }
     */
    public function getCompanyRiskSummary(Company $company, ?\DateTimeImmutable $now = null): array
    {
        $companyId = $company->getId();
        if (null === $companyId) {
            return [
                'blocked24h' => 0,
                'blocked7d' => 0,
                'cooldownActive' => false,
                'cooldownUntil' => null,
                'threshold24h' => $this->pointsPolicyCooldownThreshold24h,
                'durationMinutes' => $this->pointsPolicyCooldownDurationMinutes,
            ];
        }

        $referenceNow = $now ?? new \DateTimeImmutable('now');
        $blocked24h = $this->pointsPolicyDecisionRepository->countBlockedForCompanySince(
            $companyId,
            $referenceNow->modify('-24 hours'),
        );
        $blocked7d = $this->pointsPolicyDecisionRepository->countBlockedForCompanySince(
            $companyId,
            $referenceNow->modify('-7 days'),
        );

        $latestBlockedAt = $this->pointsPolicyDecisionRepository->findLatestBlockedAtForCompany($companyId);
        $cooldownUntil = $latestBlockedAt?->modify(sprintf('+%d minutes', $this->pointsPolicyCooldownDurationMinutes));
        $cooldownActive = $blocked24h >= $this->pointsPolicyCooldownThreshold24h
            && $cooldownUntil instanceof \DateTimeImmutable
            && $cooldownUntil > $referenceNow;

        return [
            'blocked24h' => $blocked24h,
            'blocked7d' => $blocked7d,
            'cooldownActive' => $cooldownActive,
            'cooldownUntil' => $cooldownActive ? $cooldownUntil : null,
            'threshold24h' => $this->pointsPolicyCooldownThreshold24h,
            'durationMinutes' => $this->pointsPolicyCooldownDurationMinutes,
        ];
    }
}
