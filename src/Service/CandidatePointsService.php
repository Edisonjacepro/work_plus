<?php

namespace App\Service;

use App\Entity\Application;
use App\Entity\ImpactScore;
use App\Entity\PointsLedgerEntry;
use App\Entity\User;
use App\Repository\ImpactScoreRepository;
use App\Repository\PointsLedgerEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

class CandidatePointsService
{
    private const BASE_APPLICATION_POINTS = 5;
    private const MAX_IMPACT_BONUS_POINTS = 10;

    public function __construct(
        private readonly PointsLedgerEntryRepository $pointsLedgerEntryRepository,
        private readonly ImpactScoreRepository $impactScoreRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function awardApplicationSubmissionPoints(Application $application): ?PointsLedgerEntry
    {
        $candidate = $application->getCandidate();
        $applicationId = $application->getId();
        $offer = $application->getOffer();
        $offerId = $offer?->getId();

        if (!$candidate instanceof User || null === $applicationId || null === $offerId) {
            return null;
        }

        $idempotencyKey = sprintf('application_submission_candidate_%d', $applicationId);
        if ($this->pointsLedgerEntryRepository->existsByIdempotencyKey($idempotencyKey)) {
            return null;
        }

        $impactScore = $this->impactScoreRepository->findLatestForOffer($offerId);
        $impactBonus = $this->computeImpactBonus($impactScore);
        $points = self::BASE_APPLICATION_POINTS + $impactBonus;

        $entry = (new PointsLedgerEntry())
            ->setEntryType(PointsLedgerEntry::TYPE_CREDIT)
            ->setPoints($points)
            ->setReason('Candidate points for submitted application')
            ->setReferenceType(PointsLedgerEntry::REFERENCE_APPLICATION_SUBMISSION)
            ->setReferenceId($applicationId)
            ->setRuleVersion($impactScore?->getRuleVersion() ?? ImpactScore::RULE_VERSION_V1_AUTO)
            ->setIdempotencyKey($idempotencyKey)
            ->setUser($candidate)
            ->setMetadata([
                'applicationId' => $applicationId,
                'offerId' => $offerId,
                'basePoints' => self::BASE_APPLICATION_POINTS,
                'impactBonusPoints' => $impactBonus,
                'offerImpactScore' => $impactScore?->getTotalScore(),
            ]);

        $this->entityManager->persist($entry);

        return $entry;
    }

    /**
     * @return array{balance: int, level: string, history: list<PointsLedgerEntry>}
     */
    public function getCandidateSummary(User $candidate, int $historyLimit = 20): array
    {
        $candidateId = $candidate->getId();
        if (null === $candidateId) {
            return [
                'balance' => 0,
                'level' => $this->getCandidateLevel(0),
                'history' => [],
            ];
        }

        $balance = $this->pointsLedgerEntryRepository->getUserBalance($candidateId);
        $history = $this->pointsLedgerEntryRepository->findLatestForUser($candidateId, $historyLimit);

        return [
            'balance' => $balance,
            'level' => $this->getCandidateLevel($balance),
            'history' => $history,
        ];
    }

    public function getCandidateLevel(int $balance): string
    {
        if ($balance >= 700) {
            return 'Impact Leader';
        }

        if ($balance >= 300) {
            return 'Gold';
        }

        if ($balance >= 100) {
            return 'Silver';
        }

        return 'Bronze';
    }

    private function computeImpactBonus(?ImpactScore $impactScore): int
    {
        if (!$impactScore instanceof ImpactScore) {
            return 0;
        }

        $bonus = (int) floor($impactScore->getTotalScore() * 0.10);

        return max(0, min(self::MAX_IMPACT_BONUS_POINTS, $bonus));
    }
}
