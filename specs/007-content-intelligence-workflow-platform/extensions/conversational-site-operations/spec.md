# CSO01 — Full functional specification

## Outcome

From a WordPress-administration request in ChatGPT, the operator sees either a scoped explanation, an accessible typed form, a preview or an executable **review plan**. The exact destination, owner, validator, storage adapter, grant, side effects and independent verification are declared before a save. Secret fields never enter the ChatGPT transcript or plaintext MCP args.

### Goals

- A single existing MCP connection across eligible sites; no one-off hardcoded WordPress screen for each plugin.
- Discovery → schema → form draft → validate → diff plan → review/step-up → fenced commit → independent readback → receipt → optional compensation.
- Site/actor/role/plugin/locale context changes invalidate stale drafts and approvals.
- Route all writes through provider-native hooks/Abilities/approved adapters; WordPress custom tables require certified driver, never raw SQL.
- Expose Workflows, bulk, monitor, media/SEO/CPT and multisite plans without bypassing domain policy.
- Clear AR/EN RTL accessibility and recoverable transport failure.

### Non-goals

- No runtime implementation or site deploy from this document.
- No universal arbitrary table editor, secret field inside ordinary chat, blind retries after timeouts, cross-site grants, automatic plugin installation, browser execution without registered provider, Production promotion without separate authority, or unsupervised financial/order mutation.

## Requirement families (27)

### CSO-R001 — Site Explorer

Read-only inventory of site, plugin, theme, CPT, taxonomy, option, meta and native Ability availability with capability provenance; never enumerate arbitrary private storage.

Primary acceptance gate: `CSO-G1`. Proposed operation family: `site_discover`. Mandatory rejection: **unknown plugin or stale inventory denies write**. Implementation tasks: `CSO-T001`, `CSO-T002`, `CSO-T003`. Status: **OPEN**.

### CSO-R002 — Universal Form Bridge

Build chat-facing forms from certified typed descriptors for options, post/term/user meta, CPTs, relationships and approved custom adapters; no arbitrary SQL.

Primary acceptance gate: `CSO-G2`. Proposed operation family: `form_schema`. Mandatory rejection: **unknown dynamic field defaults to read-only**. Implementation tasks: `CSO-T004`, `CSO-T005`, `CSO-T006`. Status: **OPEN**.

### CSO-R003 — Smart Autocomplete

Resolve terms, posts, linked records and select options by exact scoped queries with paging, language and cardinality.

Primary acceptance gate: `CSO-G2`. Proposed operation family: `form_suggest`. Mandatory rejection: **cross-tenant suggestion forbidden**. Implementation tasks: `CSO-T007`, `CSO-T008`, `CSO-T009`. Status: **OPEN**.

### CSO-R004 — Dependency-aware Forms

Apply declarative conditional visibility, validation and required-if constraints without executing untrusted plugin expressions.

Primary acceptance gate: `CSO-G2`. Proposed operation family: `form_validate`. Mandatory rejection: **hidden-field injection rejected**. Implementation tasks: `CSO-T010`, `CSO-T011`, `CSO-T012`. Status: **OPEN**.

### CSO-R005 — Visual Diff and Preview

Render redacted before/after values, post-render preview and estimated side effects bound to exact object revision.

Primary acceptance gate: `CSO-G3`. Proposed operation family: `change_plan`. Mandatory rejection: **preview must not mutate or claim publication**. Implementation tasks: `CSO-T013`, `CSO-T014`, `CSO-T015`. Status: **OPEN**.

### CSO-R006 — Secure Credentials Center

Collect API keys through short-lived first-party HTTPS secret handoff with origin binding and no chat/MCP plaintext; verify, rotate and revoke.

Primary acceptance gate: `CSO-G2`. Proposed operation family: `secret_session`. Mandatory rejection: **secret must never appear in chat logs or receipt**. Implementation tasks: `CSO-T016`, `CSO-T017`, `CSO-T018`. Status: **OPEN**.

### CSO-R007 — Bulk Operations

Scope and preview batches, canary first, individual idempotency keys, quotas, checkpoint per item and stop-on-error policy.

Primary acceptance gate: `CSO-G4`. Proposed operation family: `bulk_plan`. Mandatory rejection: **partial success never reported as all success**. Implementation tasks: `CSO-T019`, `CSO-T020`, `CSO-T021`. Status: **OPEN**.

### CSO-R008 — Conversation Workflow Builder

Compile natural-language intent to a typed, reviewable DAG of certified operations, conditions, waits, triggers and compensation.

Primary acceptance gate: `CSO-G4`. Proposed operation family: `workflow_compile`. Mandatory rejection: **prompt injection cannot create capabilities**. Implementation tasks: `CSO-T022`, `CSO-T023`, `CSO-T024`. Status: **OPEN**.

### CSO-R009 — History, Reconcile and Rollback

Maintain immutable redacted pre/post fingerprints, journal and supported per-provider compensation with unrecoverable disclosures.

Primary acceptance gate: `CSO-G4`. Proposed operation family: `change_history`. Mandatory rejection: **unknown commit state prohibits blind retry**. Implementation tasks: `CSO-T025`, `CSO-T026`, `CSO-T027`. Status: **OPEN**.

### CSO-R010 — Content and Media Studio

Draft posts, pages, tours/products and approved CPTs; native meta, taxonomy, relations, galleries, alt text, SEO and WPML parity.

Primary acceptance gate: `CSO-G5`. Proposed operation family: `content_plan`. Mandatory rejection: **media rights and publish permission checked**. Implementation tasks: `CSO-T028`, `CSO-T029`, `CSO-T030`. Status: **OPEN**.

### CSO-R011 — Multi-site Command Center

Inventory many sites and stage policy/template diffs per site with isolation, per-site grants and rollback evidence.

Primary acceptance gate: `CSO-G6`. Proposed operation family: `multisite_plan`. Mandatory rejection: **one-site grant does not grant another site**. Implementation tasks: `CSO-T031`, `CSO-T032`, `CSO-T033`. Status: **OPEN**.

### CSO-R012 — Approval and Delegation Center

Explain blast radius and Before/After, route to role owners, timebox exact plan hash and enforce dual-control where required.

Primary acceptance gate: `CSO-G3`. Proposed operation family: `approval_plan`. Mandatory rejection: **self-approval forbidden for segregated roles**. Implementation tasks: `CSO-T034`, `CSO-T035`, `CSO-T036`. Status: **OPEN**.

### CSO-R013 — Monitoring and Smart Alerts

Monitor drift, failures, expiration, performance and content changes with opt-in conditional alerts and deduplicated incident states.

Primary acceptance gate: `CSO-G7`. Proposed operation family: `monitor_plan`. Mandatory rejection: **stale monitoring evidence cannot claim online**. Implementation tasks: `CSO-T037`, `CSO-T038`, `CSO-T039`. Status: **OPEN**.

### CSO-R014 — Staging to Production Promotion

Produce independently certified exact-source diffs and signed artifact plans; require separate production authority and test proof.

Primary acceptance gate: `CSO-G8`. Proposed operation family: `promotion_plan`. Mandatory rejection: **staging approval never authorizes Production**. Implementation tasks: `CSO-T040`, `CSO-T041`, `CSO-T042`. Status: **OPEN**.

### CSO-R015 — Reusable Templates

Version signed form/workflow templates with semantic field mapping and compatibility migration; no hidden credential reuse.

Primary acceptance gate: `CSO-G2`. Proposed operation family: `template_plan`. Mandatory rejection: **template cannot carry authority or secrets**. Implementation tasks: `CSO-T043`, `CSO-T044`, `CSO-T045`. Status: **OPEN**.

### CSO-R016 — Contextual Help and Troubleshooting

Explain each field, storage mapping, ownership, side effects, and next safe remediation from certified metadata.

Primary acceptance gate: `CSO-G9`. Proposed operation family: `form_explain`. Mandatory rejection: **unknown behavior clearly labelled unknown**. Implementation tasks: `CSO-T046`, `CSO-T047`, `CSO-T048`. Status: **OPEN**.

### CSO-R017 — Self Diagnostics and Guided Recovery

Identify failing provider, schema, host, runtime, plugin and stored state; plan one fenced action with independent readback.

Primary acceptance gate: `CSO-G7`. Proposed operation family: `doctor_plan`. Mandatory rejection: **disabled Host/Developer lane cannot silently execute**. Implementation tasks: `CSO-T049`, `CSO-T050`, `CSO-T051`. Status: **OPEN**.

### CSO-R018 — Conversation Intent and Session Continuity

Retain redacted draft intent and resumable form sessions with TTL, actor/site fencing and explicit confirmation on commit.

Primary acceptance gate: `CSO-G2`. Proposed operation family: `form_draft`. Mandatory rejection: **old-session replay rejected**. Implementation tasks: `CSO-T052`, `CSO-T053`, `CSO-T054`. Status: **OPEN**.

### CSO-R019 — Dynamic Storage Adapter Registry

Register vendor/version-specific adapters that preserve plugin hooks, sanitizers and transactions; run conformance before writes.

Primary acceptance gate: `CSO-G1`. Proposed operation family: `adapter_certify_plan`. Mandatory rejection: **unrecognized table fails closed**. Implementation tasks: `CSO-T055`, `CSO-T056`, `CSO-T057`. Status: **OPEN**.

### CSO-R020 — Localization, RTL and Accessibility

Provide keyboard/screen-reader/RTL forms, accessible validation and localized terms without corrupting machine identifiers.

Primary acceptance gate: `CSO-G9`. Proposed operation family: `form_accessibility_report`. Mandatory rejection: **missing labels and focus traps block**. Implementation tasks: `CSO-T058`, `CSO-T059`, `CSO-T060`. Status: **OPEN**.

### CSO-R021 — Workflow Quality and Usage Telemetry

Measure completion, abandonment, retries, latency and cost with redacted audit and tenant retention policies.

Primary acceptance gate: `CSO-G9`. Proposed operation family: `ux_metrics_plan`. Mandatory rejection: **no secret/value leakage in analytics**. Implementation tasks: `CSO-T061`, `CSO-T062`, `CSO-T063`. Status: **OPEN**.

### CSO-R022 — Policy and Data Governance

Pin issuer, subject, site UUID, origin, environment, source SHA, role, capability and data residency for every operation.

Primary acceptance gate: `CSO-G1`. Proposed operation family: `policy_explain`. Mandatory rejection: **cross-origin or stale source denies**. Implementation tasks: `CSO-T064`, `CSO-T065`, `CSO-T066`. Status: **OPEN**.

### CSO-R023 — Durable Cross-plugin Orchestration

Use saga/checkpoints, dependency DAG, compensating ops, independent readback, concurrency and crash recovery.

Primary acceptance gate: `CSO-G4`. Proposed operation family: `workflow_plan`. Mandatory rejection: **no impossible global database atomicity promise**. Implementation tasks: `CSO-T067`, `CSO-T068`, `CSO-T069`. Status: **OPEN**.

### CSO-R024 — Drift and Lifecycle Management

Compare desired/actual options and deployed plugin changes; suspend stale adapters and queue human owner decisions.

Primary acceptance gate: `CSO-G7`. Proposed operation family: `drift_plan`. Mandatory rejection: **changed plugin schema invalidates stale form**. Implementation tasks: `CSO-T070`, `CSO-T071`, `CSO-T072`. Status: **OPEN**.

### CSO-R025 — Credential Lifecycle and Rotation

Manage expiry, least-privilege scopes, provider-bound rotation and zero-plaintext verification receipts.

Primary acceptance gate: `CSO-G2`. Proposed operation family: `secret_rotate_plan`. Mandatory rejection: **rotation never echoes old or new value**. Implementation tasks: `CSO-T073`, `CSO-T074`, `CSO-T075`. Status: **OPEN**.

### CSO-R026 — Event Trigger and Webhook Automation

Use authenticated event providers with signature, replay nonce, ordering and per-site rate limits.

Primary acceptance gate: `CSO-G4`. Proposed operation family: `trigger_plan`. Mandatory rejection: **spoofed webhook never mutates**. Implementation tasks: `CSO-T076`, `CSO-T077`, `CSO-T078`. Status: **OPEN**.

### CSO-R027 — Commerce and Custom Object Integration

Support products, orders and arbitrary custom objects only through certified provider APIs and business validation hooks.

Primary acceptance gate: `CSO-G5`. Proposed operation family: `object_contract`. Mandatory rejection: **payment/order side effects need distinct policy**. Implementation tasks: `CSO-T079`, `CSO-T080`, `CSO-T081`. Status: **OPEN**.


## Operating guarantees

All read disclosures respect the current permission callback and resource scope; a form field cannot increase its own visibility. Every write plan is exact-site+build+actor+source+schema+target revision bound, expires, has reviewable side effects, and is revalidated at commit. A successful tool response is not an independent persistence readback. Partial operations use per-target checkpoints and state `PARTIAL` or `UNCERTAIN` rather than false success. Recovery is compensating-saga, not blanket SQL transaction.

## Multi-site, environments and tenants

A centrally compiled plan is a collection of **independent** per-site plans. An approved Staging change cannot execute on Production, and one tenant/site cannot read another site's secrets, post IDs, meta keys, terms or completion receipts. Clone/restore, plugin-version drift, WPML locale changes and source updates revoke stale proposals. Check `contracts/multisite-and-promotion.md`.
