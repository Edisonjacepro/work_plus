# Specification Technique - Moderation simple + Points sur justificatifs v1

Date: 2026-02-17  
Projet: Work+  
Statut: Proposition prete a implementer

## 1. Objectif

Livrer un MVP simple et praticable sans IA:

- publication d'offre sans pieces justificatives obligatoires,
- attribution de points entreprise via demandes avec justificatifs,
- controle automatique deterministe (regles + APIs publiques gratuites),
- escalade humaine quand necessaire,
- audit et idempotence sur le ledger.

## 2. Regle produit centrale

- Publier une offre: possible sans justificatifs.
- Gagner des points entreprise: justificatifs obligatoires.
- Aucune attribution automatique de points a la simple publication.

## 3. Workflow cible

## 3.1 Publication d'offre

1. L'entreprise cree et publie l'offre (`DRAFT` -> `PUBLISHED`) selon le flux existant.
2. La moderation de visibilite reste possible via admin (`isVisible`).
3. Aucun credit de points n'est cree a cette etape.

## 3.2 Demande de points (`PointsClaim`)

1. L'entreprise soumet une demande de points avec type d'action et justificatifs.
2. Le systeme calcule un `evidenceScore` (0..100) a partir de regles.
3. Le systeme enrichit avec verifications externes gratuites (API publiques).
4. Decision:
- `evidenceScore >= 70`: `APPROVED` (auto possible),
- `40..69`: `IN_REVIEW` (validation humaine),
- `< 40`: `REJECTED` (raison obligatoire).
5. Si `APPROVED`, creation d'une ligne `CREDIT` dans le ledger.

## 4. Calcul deterministe sans IA

`evidenceScore` base sur:

- completude des justificatifs (40 pts),
- validite technique des fichiers (20 pts),
- coherence avec donnees entreprise/offre (20 pts),
- verifications APIs publiques (20 pts).

APIs gratuites ciblees:

- API Recherche d'Entreprises (etat, activite, identite),
- API geo.gouv (coherence geographique si necessaire),
- autres open data selon action.

## 5. Modele de donnees v1

## 5.1 Entite `PointsClaim` (nouvelle)

Champs minimum:

- `id`,
- `company` (FK obligatoire),
- `offer` (FK nullable),
- `claimType` (ex: `TRAINING`, `VOLUNTEERING`, `CERTIFICATION`, `OTHER`),
- `status` (`SUBMITTED`, `IN_REVIEW`, `APPROVED`, `REJECTED`),
- `requestedPoints`,
- `approvedPoints` (nullable),
- `evidenceDocuments` (json, au moins 1),
- `externalChecks` (json nullable),
- `evidenceScore` (0..100),
- `decisionReason` (nullable, obligatoire si rejet),
- `ruleVersion`,
- `idempotencyKey` (unique),
- `reviewedBy` (FK user nullable),
- `reviewedAt` (nullable),
- `createdAt`,
- `updatedAt`.

## 5.2 Ledger points

- `PointsLedgerEntry` reste append-only.
- Nouveau `referenceType`: `POINTS_CLAIM_APPROVAL`.
- Credit uniquement a l'approbation d'une demande.
- Solde entreprise = somme du ledger.

## 6. Services Symfony (MVC)

Services metier:

- `PointsClaimService`
- `PointsClaimScoringService` (optionnel en v1, sinon inclus dans `PointsClaimService`)
- `PointsLedgerService` (existant, etendu au nouveau cas)

Methodes minimales:

- `PointsClaimService::submit(...)`
- `PointsClaimService::markInReview(...)`
- `PointsClaimService::approve(...)`
- `PointsClaimService::reject(...)`

Controllers:

- restent minces (orchestration + validation + appel service).

## 7. Idempotence

- Chaque demande a une `idempotencyKey` unique.
- Chaque credit ledger lie a la demande utilise une cle deterministe derivee de cette demande.
- Rejouer la meme operation ne doit pas creer de doublon.

## 8. Securite et RGPD

- Aucun PII en clair dans logs metier.
- Logs: IDs techniques, statut, reason codes, timestamp.
- Validation stricte des entrees (type, taille, format).
- CSRF pour formulaires web.
- Autorisations via Voters/roles sur approbation/rejet.

## 9. Strategie de test v1

Unit tests:

- `PointsClaimServiceTest`:
- soumission valide,
- blocage soumission sans justificatif,
- transition `APPROVED` avec creation ledger,
- idempotence sur approbation,
- rejet avec raison obligatoire.

Integration tests (iteration suivante):

- endpoints de soumission/revue,
- persistence DB `points_claim`,
- coherence ledger apres approbation.

## 10. Plan d'implementation incremental

1. Mettre a jour la spec (ce document).
2. Ajouter `PointsClaim` + migration DB.
3. Ajouter `PointsClaimService` + tests unitaires.
4. Etendre `PointsLedgerEntry` pour `POINTS_CLAIM_APPROVAL`.
5. Supprimer le credit automatique de points lors de publication d'offre.
6. Ajouter endpoints/formulaires de soumission/revue (iteration suivante).

## 11. Definition of Done v1

- Publication d'offre possible sans justificatifs.
- Gain de points possible uniquement via `PointsClaim` approuve.
- Ledger coherent et idempotent.
- Tests unitaires critiques passants.
- Aucun log metier avec PII en clair.
