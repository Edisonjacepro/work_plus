# Specification Technique - Moderation Impact-Risque v1

Date: 2026-02-17  
Projet: Work+  
Statut: Proposition prete a implementer

## 1. Objectif

Mettre en place une moderation des offres basee sur le risque d'impact negatif, y compris pour des activites legales, avec:

- evaluation automatique (IA + APIs externes),
- escalation humaine en fonction du niveau de risque,
- actions admin (masquer offre, interdire utilisateur/entreprise),
- tracabilite complete pour audit,
- compatibilite avec le ledger de points append-only.

Cette moderation ne remplace pas le cadre legal; elle ajoute un filtre produit "impact positif".

## 2. Perimetre v1

Inclut:

- workflow de moderation des offres,
- moteur de decision impact-risque,
- persistance des decisions et des preuves techniques,
- actions admin de mitigation (`mask`, `ban`),
- integration avec attribution/retrait des points.

Exclut (v1):

- scoring multi-modeles avances,
- orchestration ML complexe (feature store),
- automatisation de contestation utilisateur.

## 3. Workflow cible

### 3.1 Etats offre (moderation)

Ajouter un statut de moderation distinct du statut de publication:

- `DRAFT`: brouillon edite par l'auteur.
- `SUBMITTED`: soumis, en attente de decision automatique.
- `IN_REVIEW`: revue humaine requise.
- `APPROVED`: valide pour publication.
- `REJECTED`: refuse, raison obligatoire.
- `MASKED`: masque temporairement/par decision admin.

Note: le champ `isVisible` existant reste la commande d'affichage immediate; `MASKED` est l'etat de moderation associe.

### 3.2 Sequence

1. Auteur soumet une offre (`SUBMITTED`).
2. Evaluation automatique IA/API produit:
- `riskScore` (0..100),
- `confidenceScore` (0..1),
- flags explicites.
3. Routage:
- faible risque -> approbation auto,
- risque moyen/eleve ou confiance basse -> revue humaine.
4. Moderateur decide `APPROVED` ou `REJECTED`, ou `MASKED` si mitigation immediate.
5. Action admin possible a tout moment:
- masquer une offre,
- bannir un utilisateur,
- bannir une entreprise.
6. Toute decision ecrit un evenement auditable.

## 4. Regles de decision v1

## 4.1 Inputs

- `impactNegativePotential` (0..100): intensite d'impact negatif potentiel.
- `probability` (0..100): probabilite de survenue.
- `severity` (0..100): gravite en cas de survenue.
- `confidenceScore` (0..1): confiance du resultat IA.
- `criticalFlags[]`: indicateurs critiques (contradiction, preuves absentes, signaux externes graves, recidive).

## 4.2 Calcul

Formule initiale:

`riskScore = round(0.45 * impactNegativePotential + 0.30 * probability + 0.25 * severity)`

Bornage final: `0..100`.

## 4.3 Matrice de decision

- Si `confidenceScore < 0.60` -> `IN_REVIEW`.
- Sinon si `riskScore <= 30` et pas de `criticalFlags` -> `APPROVED` auto.
- Sinon si `31 <= riskScore <= 69` -> `IN_REVIEW`.
- Sinon (`riskScore >= 70`) -> `MASKED` + revue humaine prioritaire.

Regle de surete: un `criticalFlag` force `IN_REVIEW` minimum.

## 5. Sources IA / API (abstraction)

## 5.1 Contrat applicatif

Introduire un contrat d'integration:

- `RiskEvidenceProviderInterface::collect(Offer $offer): RiskEvidenceBundle`

Un bundle contient:

- scores intermediaires,
- `confidenceScore`,
- `criticalFlags`,
- references de sources,
- hash des preuves normalisees.

## 5.2 Gouvernance

- Pas de PII inutile envoyee aux providers.
- Timeout strict par provider + fallback degrade.
- Tracer les erreurs de provider sans fuite de contenu sensible.

## 6. Modele de donnees cible

## 6.1 Offer (evolution)

Ajouter:

- `moderationStatus` (`DRAFT|SUBMITTED|IN_REVIEW|APPROVED|REJECTED|MASKED`),
- `submittedAt` (nullable),
- `moderationUpdatedAt` (nullable),
- `maskedAt` (nullable),
- `maskedReasonCode` (nullable, court).

Conserver:

- `status` publication existant (migration ulterieure possible),
- `isVisible` pour effet immediate de masquage.

## 6.2 ModerationReview (append-only)

Entite nouvelle:

- `id`,
- `offer` (FK),
- `reviewer` (FK user nullable pour auto),
- `decision` (`AUTO_APPROVED|APPROVED|REJECTED|MASKED`),
- `reasonCode` (obligatoire sauf auto clean),
- `reasonText` (nullable, redige par humain),
- `riskScore` (int),
- `confidenceScore` (float),
- `criticalFlags` (json),
- `evidenceHash` (string),
- `source` (`SYSTEM` ou `HUMAN`),
- `createdAt`.

Regle: table append-only, jamais de update metier sur les lignes historiques.

## 6.3 ActorSanction (admin)

Entite nouvelle:

- `id`,
- `targetType` (`USER|COMPANY`),
- `targetId`,
- `actionType` (`BAN_TEMP|BAN_PERM|UNBAN`),
- `reasonCode`,
- `reasonText` (nullable),
- `startedAt`,
- `endsAt` (nullable),
- `createdBy` (admin user id),
- `createdAt`.

## 6.4 Reason Codes v1

Liste initiale standardisee:

- `NEGATIVE_ENVIRONMENTAL_EXTERNALITY`
- `NEGATIVE_SOCIAL_EXTERNALITY`
- `HIGH_HARM_RISK`
- `UNSUPPORTED_IMPACT_CLAIM`
- `INCONSISTENT_CLAIM`
- `REPUTATION_RISK_SIGNAL`
- `RECIDIVISM_PATTERN`
- `ADMIN_PRECAUTIONARY_MASK`
- `ADMIN_POLICY_VIOLATION`

## 7. Services Symfony (MVC)

## 7.1 Services metier

- `ImpactRiskAssessmentService`
- `ModerationService`
- `AdminEnforcementService`
- `ModerationAuditService`

## 7.2 Methodes minimales

- `ImpactRiskAssessmentService::assess(Offer $offer): ImpactRiskAssessmentResult`
- `ModerationService::submit(Offer $offer, User $actor): void`
- `ModerationService::autoDecide(Offer $offer): AutoDecisionResult`
- `ModerationService::approve(Offer $offer, User $reviewer, string $reasonCode, ?string $note): void`
- `ModerationService::reject(Offer $offer, User $reviewer, string $reasonCode, ?string $note): void`
- `ModerationService::mask(Offer $offer, User $reviewer, string $reasonCode, ?string $note): void`
- `AdminEnforcementService::banUser(User $target, User $admin, string $reasonCode, ?\DateTimeImmutable $until): void`
- `AdminEnforcementService::banCompany(Company $target, User $admin, string $reasonCode, ?\DateTimeImmutable $until): void`

Controllers: orchestration uniquement (request, validation, appel service, response).

## 8. Integration points / ledger

## 8.1 Attribution

- Credit de points uniquement sur transition vers `APPROVED`.
- Interdit en `SUBMITTED`, `IN_REVIEW`, `MASKED`, `REJECTED`.

## 8.2 Retrait / correction

Si offre deja creditee puis `MASKED` ou `REJECTED`:

- ecrire un `DEBIT` ou `ADJUSTMENT` dans `PointsLedgerEntry`,
- reference type: `OFFER_MODERATION_REVERSAL`,
- idempotency key deterministe:  
  `offer:{offerId}:moderation:{decision}:{ruleVersion}`.

## 8.3 Idempotence

- Toute operation points/moderation sensible passe par une cle d'idempotence.
- Re-execution du meme evenement ne doit pas dupliquer les ecritures.

## 9. Securite & RGPD

- Pas de PII en clair dans logs d'audit.
- Stocker des IDs techniques, codes raison, timestamps, hash de preuves.
- Tracer la version de regle (`ruleVersion`) sur chaque decision automatique.
- Voters obligatoires pour actions moderation/admin.
- Rate limiting sur soumission offre et actions moderation sensibles.

## 10. Observabilite

Metriques minimales:

- volume de soumissions,
- taux auto-approve,
- taux escalation humaine,
- distribution `riskScore`,
- delai median de revue humaine,
- taux de renversement (auto -> correction humaine),
- nombre d'offres masquees et bannissements.

## 11. Strategie de test v1

## 11.1 Unit tests (obligatoire)

- `ImpactRiskAssessmentServiceTest`: calcul score, seuils, flags critiques.
- `ModerationServiceTest`: transitions d'etat valides/interdites.
- `AdminEnforcementServiceTest`: ban temp/permanent, unban.
- `PointsLedgerServiceTest` (maj): credit sur `APPROVED`, debit sur `MASKED/REJECTED`.

## 11.2 Integration tests

- persistence `ModerationReview` append-only,
- endpoint submit + auto decision,
- endpoint review humaine,
- endpoint admin mask/ban.

## 12. Plan d'implementation incremental

1. Migrations DB (`offer` moderation fields + tables `moderation_review` et `actor_sanction`).
2. DTO/result objects de decision (`ImpactRiskAssessmentResult`, `AutoDecisionResult`).
3. Services metier et interfaces providers.
4. Controllers + formulaires admin/review.
5. Voters moderation/admin.
6. Integration ledger points (credit/debit idempotent).
7. Tests unitaires + integration.
8. Observabilite (logs/metriques) et ajustement seuils.

## 13. Risques connus

- Faux positifs/faux negatifs IA: mitiges par escalation humaine.
- Variabilite des APIs externes: mitigee par timeout + fallback + cache court.
- Effet produit des seuils: calibration necessaire sur donnees reelles.

## 14. Definition of Done v1

- Workflow moderation actif bout-en-bout.
- Decisions tracables en base.
- Actions admin mask/ban operationnelles.
- Ledger points coherent avec decisions moderation.
- Tests critiques passants.
- Aucun log PII en clair.
