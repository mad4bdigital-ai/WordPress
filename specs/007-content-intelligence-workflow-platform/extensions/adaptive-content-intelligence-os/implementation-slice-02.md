# ACI01 — Implementation Slice 02: Governed evidence read

**Contract:** `mad4b.aci01.evidence-preview.v1`  
**Source state:** `IMPLEMENTED_SOURCE_AWAITING_INDEPENDENT_PHP_AND_STAGING`  
**Effect:** `PURE_READ` only, no ContentJob state changes, journal inserts, content creation, external calls, grants or automatic publication.

## Reused authoritative domain readers
- `MAD4B_SCP_Content_Jobs::get_job` resolves one exact site-scoped ContentJob.
- `MAD4B_SCP_Context_Pack::preview` resolves the approved brand knowledge classes and missing classes for the exact ContentJob without `build` (which would append an artifact).
- `MAD4B_SCP_Artifacts::get_artifact` retrieves up to 12 explicitly supplied research IDs, with site-level filtering. This slice never calls unbounded `list_artifacts`, and never auto-selects data from another job.
- `MAD4B_SCP_Site_Profile` supplies exact site identity and environment. Existing `MAD4B_SCP_Policy::can_read` remains the independent read gate.

## Security / trust invariants

`job_id` is compulsory. Inputs accept only bounded `artifact_ids` and a freshness budget (maximum seven days); unrecognized keys and malformed identifiers are denied. The caller cannot replace the resolved job brand, locale, market, site identity or approved context. Every artifact must be `active`, belong to the same job, and be one of `keyword_research` / `serp_research`; provider ID, request fingerprint and source reference shape must be valid. Future or expired research becomes a visible blocker. Bounded research receipts never count as provider certification or source-rights authority.

The output never projects raw context excerpts, research HTML, credentials, captured source text, customer data or arbitrary artifact payloads. Only read-only identifiers, fingerprints, timestamps, bounded source counts, missing context categories and next-step reason codes are exposed. The result always stays `NEEDS_EVIDENCE`; no caller-supplied `ready=true` can authorize a publication.

All response branches advertise `authorizing=false`, `mutation_performed=false`, `paid_calls=0`, `provider_calls_performed=false` and `eligible_for_mutation=false`.

## Hermetic checks

```bash
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-aci01-evidence-preview.php
php wp-content/plugins/mad4b-site-control-plane/tests/aci01-evidence-read-contract.php
```

The fixture asserts read-only Ability registration, deterministic projection, required brand gaps, refusal of duplicate/broad artifact references, unknown fields, revoked policy, unenrolled site, brand/job crossover, expired research, unverified usage rights, absent source refs, and no propagation of untrusted raw text.

**Exact-head PHP execution and Staging/native readback remain independently pending until observed.** GitHub CI is not used as an oracle during its outage. The native Artifacts and ContextPack services may themselves fail on an environment with incomplete schema/brand registry; such failures must be surfaced as a denied/bounded preflight, not recovered by side effects.

## Dependency and next increment
- Slice 01: `mad4b/aci01-intake-preview` yields the post-type capability and conditional relation review.
- Slice 02: `mad4b/aci01-evidence-preview` yields current ContextPack requirements and bounded read-only research evidence.
- Slice 03: a pure, never-authorizing EvidencePack/Opportunity/Blueprint candidate compiler, with goal, source citations and context-bound review inputs; real artifact append uses already-governed Feature 007 registries only after independent write authorization.

All Spec Kit task states remain OPEN until each applicable runtime/certification gate has readback evidence.

## P0 follow-on hardening — source reference integrity

The bounded ACI01 Evidence read projection now rechecks the **existing** actor read grant after ContentJob, ContextPack and Artifact reads, in addition to the runtime-generation/restore-epoch fence. A revoked permission during the read denies the response; it cannot produce a stale, apparently current evidence preview.

Research artifact source references remain untrusted **locators**, not fetched source text. A locator may be a bounded opaque string or a flat typed map containing a recognized URI/native-ID locator field; nested raw HTML/array graphs, missing locator identity, control characters, oversized source/provider identifiers and overly large metadata are refused. Locators and raw normalized research are never exposed in the return payload, which reports counts and digests only.

The original Feature 007 `append_research` input is untouched. Such upstream receipts remain **unlicensed and unverified** until independent provider, rights and fact authority checks. Existing evidence QA fixtures are extended with valid typed refs, negative malformed/nested/oversized refs and midflight read grant revocation. Native PHP/Staging tests remain **NOT_RUN** until an exact-head runtime is available.

## G2 → G3 continuity — provenance groups, exclusions, coverage proposal

Repeated research Artifacts with the same **provider ID, request fingerprint and observation timestamp** are one logical observation, not independent research. For the same identity, conflicting normalized evidence/source locators produce `conflicting_research_response_observed`; repeats produce `duplicate_research_request_observed`. The first immutable Artifact is selected by sorted ID, independent of request order. Source locators are also hashed and deduplicated across Artifacts. Different observation timestamps are not conflated with identical responses.

The Evidence Preview returns a bounded `mad4b.aci01.provenance-observation.v1` with only counts, SHA-256 group digests and excluded Artifact IDs — never raw source extracts. It also proposes a **non-persisted** `mad4b.aci01.evidence-coverage-candidate.v1` referring to the existing `evidence_coverage_matrix` Artifact type and `mad4b/artifact-append` Ability. This is a **handoff for independent approval**, not an append call, completed EvidencePack, provider entitlement or license. All candidates have `authorizing=false` and `dispatch_allowed=false`.

The downstream Opportunity Preview validates bounded provenance membership/cardinality, discards explicitly duplicated observations from the suggested Blueprint research IDs, and keeps conflicts and repeated source locators in independent-review status. Any forged `independently_reviewed=true`, `authorizing=true`, unbounded/duplicate conflict hash, unknown exclusion, or mismatch in source counts is denied.

**Acceptance scope:** offline PHP fixtures for duplicate Artifact IDs, source overlap across separate requests, contradictory same-observation payloads, different-timestamp recrawls, order permutation determinism, non-dispatchable coverage proposal, and tampered cross-source handoff cardinalities. Actual PHP 7.4/8.3 execution, reusable native research fixture and rights receipts must be recorded separately; source additions do not close all G2/G3 tasks.
