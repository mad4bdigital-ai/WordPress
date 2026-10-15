# Data Model — Typed, auditable, versioned

Contract: `mad4b.aci-os.data-model.v1`. Logical entities; do not create tables by virtue of this document. Prefer existing Feature 007 artifacts/journals and WordPress native storage.

## Shared identity envelope

Every durable record: `tenant_id`, `site_uuid`, `canonical_origin`, `profile_revision`, `runtime_generation`, `restore_epoch`, `record_id`, `schema_version`, `created_at`, `evidence_generation`, `authorizing=false` unless produced by existing authority. Optional brand/profile locale/market/account IDs follow policy. External secrets and raw access tokens never appear in artifacts.

## Logical entity registry

| Entity | Key fields | Owner / retention / boundary |
|---|---|---|
| SiteProfile | site_uuid, origin, environment, generation | MAD4B Site Profile; no inferred authority |
| BrandContextRevision | brand, strategy, voice, guideline digests, approval | Context Authority |
| WriterProfile | locale, author/style revision, samples/rights digest | Context/Skills; do not copy source corpus |
| ContentInventoryItem | native post ID/type/status, locale, edit-lock, canonical, last modified | WordPress governed read snapshot |
| SemanticFieldDefinition | source_type, field_key, target_kind/type, locale_policy, owner_provider | versioned registry, manual review |
| NativeRelationSnapshot | source semantic identity, field, raw typed target ID, value fingerprint | evidence-only; no generated rewrite |
| TranslationIdentity | WPML element_type, trid, language, element_id, original/fallback flag | WPML provider read |
| RelationFinding | field, source/target namespace, structural/semantic state, reason, evidence refs | immutable diagnostic |
| OpportunityHypothesis | topic/intent/locale, cost/value/certainty and alternatives | decision engine |
| ResearchRequest | provider, account, scope, estimated cost, reservation, idempotency key | budget/adapter |
| EvidenceSource | URL/native identifier, retrieval time, rights, provider, content SHA, trust | Evidence Registry |
| EvidencePack | scoped evidence IDs, exclusions, conflicts, freshness, data rules | immutable artifact |
| ContextPack | approved brand/source pointers, allowed use, expiry, lineage | Context Authority |
| ExperienceGapMatrix | entity/question coverage, importance, gaps, evidence refs | competitive intelligence |
| ContentBlueprint | intent, locale, outline, citations, links, CTAs, risk | planning |
| DraftArtifact | sections, source claims, model/prompt revision, rights | write stage; not publication |
| QualityVerdict | gate type, policy/version, finding IDs, reviewed actor | evaluator stage |
| MediaAssetPlan | rights, image purpose, accessibility, variants, WP ID mapping | media bridge |
| PublishManifest | exact post/meta/media/SEO/translation intent, actor scopes and dependencies | governed publish plan |
| ContentJob | state, stage, attempt, lease, deadline, cost and recovery state | durable orchestration |
| JobStageReceipt | before/after artifact fingerprints, executor, output, side effect class | existing operation journal |
| GrowthObservation | site/property, locale, device, window, metric and confidence | external measurement |
| OptimizationProposal | comparison key, hypothesis, predicted costs, rollback/experiments | decision only |
| OperatorTicket | blocker, options, required actor/approval, expiry and evidence | Action Center |

## Stable semantic identity vs native WordPress identity

`SemanticContentIdentity` is a site-bound, type-bound, intent-scoped stable key referencing native WordPress IDs as mutable locale-specific implementations. Native post IDs and term IDs must never be compared outside namespace:

```json
{
  "site_uuid": "uuid",
  "entity_kind": "term",
  "taxonomy": "package_category",
  "id_scheme": "term_id",
  "local_id": 2288,
  "locale": "en",
  "provider": "wpml",
  "translation_group": "provider-trid",
  "semantic_identity": "site-local-policy-owned-key"
}
```

Use `post_type` for post identity, `taxonomy` and `term_id` versus `term_taxonomy_id` for term identity. Do not use a bare `known[id]` registry. WPML translation group equivalence is necessary but not sufficient for business semantic equivalence; policy owner remains independent.

## Journal and consistency

Canonical job state: `DRAFT -> RESEARCHING -> PLANNING -> WRITING -> QUALITY_REVIEW -> PUBLISH_REVIEW -> READY_TO_PUBLISH -> PUBLISHED -> OBSERVING`, with `PAUSED/QUARANTINED/CANCELLED/FAILED` guarded terminals or transitions. State is separate from current stage and from authority. Every update uses generation-fenced CAS and immutable event; external UNKNOWN effect suspends dependent stage. Repeated event IDs never append twice.

Artifacts form a directed acyclic graph with explicit parent hashes, source/target types, producer contract, version and expiry. Recompute only proven descendants; preserve human edits through three-way diff. PII must not be used as a stable cross-tenant content hash.

## Storage decisions

- Native content/postmeta/taxonomies/translations remain owned by WordPress/provider.
- MAD4B domain artifacts persist through its existing governed artifact registry, not an ad-hoc plugin table unless migration reviewed.
- Persist bounded evidence snippets and durable source pointers according to rights and retention; no unrestricted competitor cache.
- Erasure/retention affects derived indexes and caches without corrupting immutable audit semantics; preserve minimised signed tombstones when allowed.
