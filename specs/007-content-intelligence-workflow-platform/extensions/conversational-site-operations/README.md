# CSO01 — MAD4B Conversational Site Operations | Spec Kit

**Parent:** Feature 007 / PR #258 · **scope:** CSO01 read-foundation implemented in a candidate PR; remaining families are SPEC_BACKLOG_ONLY · **authority:** none · **production:** not approved.

This extension designs a generic, portable WordPress operating experience where a conversation may discover certified data fields, display editable forms, propose plans and submit independently authorized operations. **No field discovery grants permission to change its value.** CSO01 is the architectural umbrella for Universal Conversation Form Bridge plus all 12 operational additions and Smart Autocomplete, Dependency-Aware Forms, Reusable Templates and Contextual Help.

## Reading order

1. `constitution.md` — unchanged MAD4B governance and secret boundaries.
2. `spec.md`, `requirements.json`, `use-cases.json` — complete product scope and negative cases.
3. `architecture.md`, `data-model.md`, `ui.md` — domain and user experience.
4. `contracts/` — typed form, operation, secrets, provider, workflow and multisite interfaces.
5. `abilities.json` — **proposed only**, not registered runtime WordPress abilities.
6. `threat-model.md`, `acceptance.md`, `gates.json` — deny cases and independent evidence.
7. `plan.md`, `tasks.md`, `tasks.json`, `traceability.md` — phased delivery and ownership.
8. `validate.py`, `test_validate.py` — offline-only, no-CI Spec Kit consistency checks.

## Explicit boundary

Reuses the current site Control Plane's Unified Capability Gateway, capability descriptors, semantic field contracts, NHI/OAuth and subject mapping, Site Profile, budget/approval/Mutation Manager, provider certification, signed receipts, Content Jobs, and Host authority without a second policy/execute plane. Vendor discovery is a **hint**, not writable certification.

No generic `wp_options` dump, arbitrary custom-table SQL, arbitrary `wp eval`, generic shell, unrestricted plugin-option setter, secret transmission in the chat, automatic Production promotion, or forged browser/rollback success.

The WordPress Abilities API supplies individually registered abilities with an input/output schema and permission checks; it does **not** itself offer an arbitrary-configuration bridge or guarantee that any plugin's option is safely mutable. WP 6.9+ support is feature-detected. Schemas exposed as WordPress abilities must remain compatible with the WP-supported JSON Schema subset.

**Scope accounting:** 27 requirement families, 81 OPEN tasks, 11 OPEN gates, 30 proposed ability endpoints (27 original + 3 read-only discovery/UX extensions), 26 negative-tested user journeys. None change the frozen parent Feature 007 release gates or its current acceptance/CI denominator.

Reference integration: `../../contracts/governed-tool-execution.md`, `../../contracts/schema-contract-evolution.md`, `../../contracts/evidence-attestation-trust.md`, `../../contracts/data-flow-policy.md`, `../competitive-experience/README.md`.

## Runtime foundation candidate (non-authorizing)

The first **read-only source slice** registers `cso/discover`, `cso/form-catalog`, `cso/integration-search`, `cso/integration-inspect`, `cso/form-schema`, `cso/form-validate`, and `cso/form-explain` using the existing `mad4b-read` server, Site Profile, Capability Descriptor Registry, and explicitly enrolled administrator permission. It does **not** infer writable adapters from plugin names or allow secret fields. Form compilation requires the existing read-server tool allowlist, a canonical read execution-lane binding, an explicitly readonly WordPress Ability and a restricted scalar typed schema. Readback re-checks site identity; validation never saves or echoes submitted values. All CSO01 requirements and acceptance gates remain OPEN pending native CI, Staging/Browser and owner certification.

To obtain editable forms, secure handoff, transactional bulk/workflow execution or Production promotion requires *separate* future certified slices and fresh grants. This slice does not add any write/approval/grant endpoint.

### Safe operator experience (candidate)

Start with `cso/form-catalog` using optional `query` and `page=0`. Each entry contains a human-readable label, exact Ability name, `form_ready` status, and a non-authorizing reason for unsupported typed schemas; 12 results per page, bounded to 256 trusted read tools. A subsequent page must supply `expected_catalog_sha256` returned by page 0, preventing silently stale navigation. Choose a ready item, call `cso/form-schema`, render its `ui` and typed field hints, then call `cso/form-validate` with the exact descriptor digest; errors identify safe field keys and next steps, never input values. `valid=true` means **validation only**, not saved, approved or published.

No WordPress admin screen, ChatGPT client form renderer or accessibility acceptance is certified by these PHP contracts. Real client UI requires separate rendering/interaction proof. The 30-row `abilities.json` catalog remains **design-level proposed metadata**; registration of seven read-only candidate runtime endpoints does not authorize the remaining proposed operations.

### Integration compatibility: observations vs permissions (Oct 10, 2026)

All Royal Egypt Staging (`wp 7.1.3`, `php 8.3.35`, runtime `rc.96`) exposed **644** WordPress Abilities to governed catalog search, while session-safe diagnostics reported **36** ChatGPT registered tools and **75** governed write inventory entries. These are distinct measures: discovery is *not* direct tool exposure, readiness, execution, or permission. Broad projection-status and read-tool inventory requests sometimes failed internally, while scoped search and exact six-provider preparation succeeded. The staging site still runs rc.96, **not this PR**.

Observed input schemas: Elementor dynamic tags requires `post_id:int >=1`; JetEngine CPT requires `post_type:string length 1–20`; Fluent Forms list uses optional `limit:int 1–100`; WooCommerce product read requires `product_id:int >=1`; Rank Math read uses root `default: []` and boolean default hints; WPML status supplies no inputs. CSO01 now compiles this scalar subset and rejects non-supported polymorphic/nested/repeater/HTML/credential-bearing schemas. All scalar defaults are **indicated but never returned**, and validation remains *stateless/no save*.

New provider-neutral `cso/integration-search` runs bounded **metadata-only** search using the existing Unified Capability Gateway and marks all rows provisional. `cso/integration-inspect` prepares one exact catalogued read Ability, verifies the canonical read lane and input identity, then returns a typed no-save form or explicit `unsupported_schema`/`schema_transport_required`; it never echoes raw provider data, invokes a vendor executor, or grants write rights. `cso/form-validate` and `cso/form-explain` can use the same exact provider form, with live actor/site/descriptor revalidation. Other integration families, rights, deployment, and runtime acceptance require separate certificates and independent readbacks. More details: [integration-compatibility.md](integration-compatibility.md).

**Installed runtime disclaimer:** Live All Royal Staging currently uses Control Plane `rc.96`, and the live ability catalog returned no `cso/integration-*` matches. PR #368 has not been installed, deployed, or tested on that site. Read-only metadata from existing Elementor/JetEngine/Rank Math/WPML/Fluent Forms/WooCommerce Abilities informed the source fixtures but is not a signed Staging acceptance receipt.

### More adversarial integration hardening — read-only candidate

- **Search fairness on complex sites:** the canonical Unified Capability Gateway now supports a typed `declared_readonly_only` search option. The filter runs **before** relevance ranking/top-25 truncation, preventing writes from hiding related reads. Arabic Alef and Tashkeel normalization now operate in the shared discovery scorer, not only in the narrow CSO01 catalog; identifiers and submitted values remain untouched.
- **Sensitive metadata:** token-looking values embedded in natural-language search are rejected before gateway dispatch. Provider-supplied enum options containing apparent personal addresses, HTML or secret labels are refused rather than passed to a ChatGPT dropdown. This heuristic is protective but NOT a complete DLP service; certified field-level disclosure and dedicated secret ingress remain future slices.
- **Runtime proof:** read-only candidate `cso/integration-inspect` distinguishes exact provider certification, version drift/unavailability and uncertified schema previews. A form marked `form_ready` is **renderable** only; it is never execution authorization.
- **Per-field source arbitration:** [dynamic-integration-resolution.md](contracts/dynamic-integration-resolution.md) specifies precise Site/Blog scope, provider/version identity, semantic field owner, Google Drive/WordPress source precedence, conflict fencing, partial multi-step outcomes, compensations and acceptance matrices. That document defines future workflow/adapter contracts, not currently available WordPress writes.
- **Native tests:** `cso01-unified-discovery-funnel-runtime.php` runs an adversarial 40-write-vs-1-read ranking scenario plus Arabic-only metadata normalization, in addition to the earlier 7 Ability fixture contracts. This remains **unverified** until exact-head PHP 7.4 and 8.3 CI completes.
