<?php

namespace App\Service;

use App\Entity\Offer;

class PublicApiEvidenceService implements ImpactEvidenceProviderInterface
{
    public function __construct(
        private readonly string $companySearchApiUrl,
        private readonly string $geoApiUrl,
        private readonly int $externalApiTimeoutSeconds,
    ) {
    }

    public function collectForOffer(Offer $offer): array
    {
        $companyName = $offer->getCompany()?->getName();
        $companyEvidence = $this->verifyCompanyByName($companyName);

        $description = $offer->getDescription() ?? '';
        $locationEvidence = $this->verifyPostalCodeInText($description);

        return [
            'company' => $companyEvidence,
            'location' => $locationEvidence,
            'sources' => array_values(array_filter([
                $companyEvidence['source'] ?? null,
                $locationEvidence['source'] ?? null,
            ])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyCompanyByName(?string $companyName): array
    {
        $trimmedName = trim((string) $companyName);
        if ('' === $trimmedName) {
            return [
                'checked' => false,
                'found' => false,
                'active' => false,
                'isEss' => false,
                'isMissionCompany' => false,
                'hasGesReport' => false,
                'source' => null,
            ];
        }

        $url = sprintf(
            '%s?q=%s&page=1&per_page=5',
            rtrim($this->companySearchApiUrl, '?&'),
            rawurlencode($trimmedName)
        );

        $payload = $this->requestJson($url);
        if (null === $payload || !isset($payload['results']) || !is_array($payload['results'])) {
            return [
                'checked' => false,
                'found' => false,
                'active' => false,
                'isEss' => false,
                'isMissionCompany' => false,
                'hasGesReport' => false,
                'source' => 'recherche-entreprises.api.gouv.fr',
            ];
        }

        $result = $this->pickBestCompanyMatch($trimmedName, $payload['results']);
        if (null === $result) {
            return [
                'checked' => true,
                'found' => false,
                'active' => false,
                'isEss' => false,
                'isMissionCompany' => false,
                'hasGesReport' => false,
                'source' => 'recherche-entreprises.api.gouv.fr',
            ];
        }

        $complements = isset($result['complements']) && is_array($result['complements'])
            ? $result['complements']
            : [];
        $active = 'A' === (string) ($result['etat_administratif'] ?? '');

        return [
            'checked' => true,
            'found' => true,
            'active' => $active,
            'isEss' => (bool) ($complements['est_ess'] ?? false),
            'isMissionCompany' => (bool) ($complements['est_societe_mission'] ?? false),
            'hasGesReport' => (bool) ($complements['bilan_ges_renseigne'] ?? false),
            'source' => 'recherche-entreprises.api.gouv.fr',
        ];
    }

    /**
     * @param array<int, mixed> $results
     * @return array<string, mixed>|null
     */
    private function pickBestCompanyMatch(string $companyName, array $results): ?array
    {
        $normalizedTarget = $this->normalize($companyName);
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $names = [
                $result['nom_complet'] ?? '',
                $result['nom_raison_sociale'] ?? '',
            ];

            foreach ($names as $name) {
                if ($this->normalize((string) $name) === $normalizedTarget) {
                    return $result;
                }
            }
        }

        foreach ($results as $result) {
            if (is_array($result)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyPostalCodeInText(string $text): array
    {
        preg_match_all('/\b\d{5}\b/', $text, $matches);
        $postalCodes = array_slice(array_values(array_unique($matches[0] ?? [])), 0, 2);

        if ([] === $postalCodes) {
            return [
                'checked' => false,
                'validated' => false,
                'postalCode' => null,
                'source' => null,
            ];
        }

        foreach ($postalCodes as $postalCode) {
            $url = sprintf(
                '%s?codePostal=%s&fields=nom,code,population&format=json&geometry=centre',
                rtrim($this->geoApiUrl, '?&'),
                rawurlencode((string) $postalCode)
            );

            $payload = $this->requestJson($url);
            if (is_array($payload) && [] !== $payload) {
                return [
                    'checked' => true,
                    'validated' => true,
                    'postalCode' => (string) $postalCode,
                    'source' => 'geo.api.gouv.fr',
                ];
            }
        }

        return [
            'checked' => true,
            'validated' => false,
            'postalCode' => null,
            'source' => 'geo.api.gouv.fr',
        ];
    }

    private function normalize(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if (false === $ascii) {
            $ascii = $normalized;
        }

        return preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '';
    }

    /**
     * @return array<string, mixed>|array<int, mixed>|null
     */
    private function requestJson(string $url): array|null
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->externalApiTimeoutSeconds,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: workplus-impact-bot/1.0\r\n",
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (false === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
