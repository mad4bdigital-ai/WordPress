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
