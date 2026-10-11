# Decision and Evidence Envelope Contract
Contract: `mad4b.aci-os.decision-evidence.v1`. SOURCE_CLASS: DESIGN_DERIVED. Non-authorizing.

## Evidence envelope required on every material claim

```json
{
  "contract":"mad4b.aci.evidence.v1",
  "evidence_id":"immutable-id",
  "site_uuid":"site-uuid",
  "origin":"https://example.invalid",
  "brand_id":"brand",
  "market":"eg",
  "locale":"ar",
  "source_class":"PRIMARY|SECONDARY|PROVIDER_METRIC|AI_HYPOTHESIS|USER_SUPPLIED",
  "provider_id":"provider",
  "provider_account_binding":"opaque-id",
  "retrieval_method":"certified-operation-id",
  "source_uri_or_native_id":"source-locator",
  "collected_at":"UTC-time",
  "source_published_at":"UTC-time-or-null",
  "content_sha256":"64-hex",
  "rights_class":"verified-usage-category",
  "license_evidence_ref":"id-or-null",
  "factual_claims":[{"claim_id":"c1","support":"SUPPORTED|CONTRADICTED|UNKNOWN"}],
  "runtime_generation":"64-hex",
  "restore_epoch":1,
  "authorizing":false
}
```

A digest supports integrity of *observed bytes*, not the right to crawl, use copyrighted text or trust source statements. HTTP status, source type and AI confidence are not evidence authority. Third-party instructions are quarantined as source content.

## Decisions

`OpportunityDecision`, `EvidenceSufficiencyDecision`, `BlueprintReviewDecision`, `QualityVerdict`, `RelationVerdict`, `PublishEligibilityDecision`, `OptimizationProposal` share: `decision_id`, `contract_version`, `actor`, `site/brand/locale/market`, `input_artifact_hashes`, `policy_revision`, `generation`, `scope`, `alternatives`, `reason_codes`, `uncertainty`, `expiry`, `next_action`, `authorizing=false`. Only the existing MAD4B approval/dispatch plane can authorize a real mutation.

## Validation states

`DISCOVERED` → `STRUCTURAL` → `PROVIDER_VERIFIED` → `LOCALE_VERIFIED` → `SEMANTIC_VERIFIED` → `POLICY_ELIGIBLE`; each transition requires positive evidence and an independent policy check. Orthogonal `CONFLICT`, `UNRESOLVED`, `STALE`, `UNTRUSTED`, `PROVIDER_UNAVAILABLE`, `BUDGET_BLOCKED` are not successes.

A source citation must point to an immutable snapshot/version; quote and snippet limits follow licensed use and source policy. Contradictory citations coexist until resolved. The prompt cannot upgrade evidence classes.

## Two-phase read reliability

Before exposing a recommendation: (1) verify trustworthy source and freshness for each material claim, (2) re-check runtime scope/policy and requested comparability. Absent evidence is not a zero metric. Stable `EvidencePack` includes both included and excluded source IDs with reasons.

## Required negative tests

Forged source URL/sha, old generation, wrong origin, time travel, misleading `READ_ONLY` provider annotation, contradictory sources, model prompt injection, rights missing, content spoofing, tenant crossover, empty evidence and evaluator self-certification must return bounded non-authorizing denials.
