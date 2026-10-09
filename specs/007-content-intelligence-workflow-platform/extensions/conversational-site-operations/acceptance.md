# CSO01 — Independent acceptance and adversarial challenge matrix

## Gate exits (all OPEN)

- **CSO-G0 spec_integrity**: depends on [none]. Proof: spec-validator-reports-zero-faults. State: OPEN; completion claim forbidden until exact-head independent evidence.
- **CSO-G1 site_identity_and_discovery**: depends on [CSO-G0]. Proof: exact-site-schema-provider-inventory-and-denial. State: OPEN; completion claim forbidden until exact-head independent evidence.
- **CSO-G2 forms_and_secret_safety**: depends on [CSO-G1]. Proof: field-validation-and-secret-nonexposure. State: OPEN; completion claim forbidden until exact-head independent evidence.
- **CSO-G3 changes_and_approvals**: depends on [CSO-G2]. Proof: exact-revision-diff-approval-recheck-and-idempotency. State: OPEN; completion claim forbidden until exact-head independent evidence.
- **CSO-G4 orchestration_and_bulk**: depends on [CSO-G3]. Proof: partial-failure-checkpoint-reconcile-compensate. State: OPEN; completion claim forbidden until exact-head independent evidence.
- **CSO-G5 content_and_media**: depends on [CSO-G3]. Proof: native-content-meta-taxonomy-media-and-seo-verification. State: OPEN; completion claim forbidden until exact-head independent evidence.
- **CSO-G6 multisite_and_tenants**: depends on [CSO-G4]. Proof: tenant-site-boundary-and-nonpropagating-authority. State: OPEN; completion claim forbidden until exact-head independent evidence.
- **CSO-G7 operations_monitoring_and_recovery**: depends on [CSO-G4]. Proof: alerts-drift-diagnostics-and-independent-recovery. State: OPEN; completion claim forbidden until exact-head independent evidence.
- **CSO-G8 staging_release_promotion**: depends on [CSO-G5, CSO-G6, CSO-G7]. Proof: signed-exact-artifact-prod-separate-approval. State: OPEN; completion claim forbidden until exact-head independent evidence.
- **CSO-G9 accessible_usability_and_cost**: depends on [CSO-G2]. Proof: rtl-screenreader-slow-network-and-performance. State: OPEN; completion claim forbidden until exact-head independent evidence.
- **CSO-G10 adversarial_live_acceptance**: depends on [CSO-G8, CSO-G9]. Proof: external-provider-mixed-fault-and-owner-attestation. State: OPEN; completion claim forbidden until exact-head independent evidence.

## Twenty-six end-to-end use cases

| ID | Real operator task | Requirement | Independent denial test |
|---|---|---|---|
| UC01 | View all configurable Rank Math fields; inspect allowed descriptors and read-only values | CSO-R001 | enumeration of private options refused |
| UC02 | Change SEO on one page from a typed conversation form | CSO-R002 | stale post revision blocks commit |
| UC03 | Create a tour draft with images, taxonomies, WPML and custom relations | CSO-R010 | media rights and translation mismatch blocks publication |
| UC04 | Set an external API key without including it in chat or MCP request | CSO-R006 | copied/replayed secret handoff URL rejected |
| UC05 | Edit SEO for fifty tour pages after impact diff, canary and per-item results | CSO-R007 | third item failure shows partial receipt |
| UC06 | Build workflow: draft tour → SEO QA → approval → publish | CSO-R008 | untrusted source injects publish command denied |
| UC07 | Explain what changed and rollback a supported plugin option | CSO-R009 | unknown target current revision disables undo |
| UC08 | Apply form template to three sites with differing plugin versions | CSO-R011 | site B cannot reuse site A grant |
| UC09 | Approve a dangerous update with exact impact, owner and expiry | CSO-R012 | self-approval and expired ticket rejected |
| UC10 | Detect API credential expiration and propose rotation | CSO-R013 | alert cannot expose key |
| UC11 | Promote signed Staging change to Production after independent certification | CSO-R014 | missing Prod authority always blocks |
| UC12 | Autocomplete destinations and relationship links from scoped taxonomy | CSO-R003 | foreign-tenant values cannot be selected |
| UC13 | Show conditional fields for chosen provider and reject hidden injected values | CSO-R004 | invisible value escalation denied |
| UC14 | Explain what a plugin option changes and identify responsible adapter | CSO-R016 | unregistered plugin option labelled unsupported |
| UC15 | Resume a draft conversation after session expiry and reconnect | CSO-R018 | expired approval cannot be reused |
| UC16 | Create a reusable trip publishing template with no credentials | CSO-R015 | template secret fields are references only |
| UC17 | Diagnose disconnected browser provider and recover without Breakglass | CSO-R017 | Host prerequisite absent prevents execution |
| UC18 | Receive signed webhook to create a draft, not publish | CSO-R026 | duplicate nonce and invalid signature dropped |
| UC19 | Run accessible RTL keyboard-only form from phone | CSO-R020 | missing focus/tab order fails usability |
| UC20 | Edit WooCommerce inventory/order using certified object provider | CSO-R027 | uncertified order side effects denied |
| UC21 | Detect plugin schema drift during form editing | CSO-R024 | stale form cannot commit after update |
| UC22 | Run large DAG with per-step checkpoint and recovery | CSO-R023 | unknown partial commit escalates to reconcile |
| UC23 | Rotate an external provider key without secret display | CSO-R025 | old secret never appears in logs |
| UC24 | Inspect engagement/completion metrics without sensitive form values | CSO-R021 | PII not present in telemetry |
| UC25 | Propose a new custom-table adapter after provider discovery | CSO-R019 | unapproved table never writable |
| UC26 | Explain cross-tenant data residency and policy denial | CSO-R022 | cross-site query cannot leak existence |

## Mandatory platform acceptance

- **Discovery**: a plugin absent from inventory, swapped implementation, wrong version or adapter conformance FAIL may offer only a read-only explanation or explicit unsupported.
- **Fields**: nested repeaters, field arrays, unicode Arabic, conditional hidden fields, object relations and dynamic taxonomies must be validated by the source adapter, not frontend-only JavaScript.
- **Secrets**: test transcript/MCP/audit/DLQ/screen reader/browser history/referer/logs for zero plaintext. Simulate expired/replayed/other-origin handoff.
- **Write/recovery**: race two writers on same revision, callback failure after partial commit, timeout with committed side effect, retry with identical idempotency, provider timeout then independent readback, plugin schema change mid-form.
- **Bulk**: 50 targets, one deny, one timeout, one outdated item and one failed rollback must preserve exact per-item records and no invented atomicity.
- **Workflow**: cycles, reordered webhooks, prompt injection, double publish and missing provider must remain blocked. Crash-restart preserves durable stages.
- **Multi-site and Production**: duplicate post IDs in distinct sites, differing tenant locale, cloned origin, stolen token, grant copied across sites, Staging promotion without Prod approval; all denied.
- **Accessibility and performance**: AR/EN RTL LTR code fragments, mobile, keyboard-only, assistive technology, offline/slow network, 0-latency fake success, slow options reads and provider budget exhaustion.
- **Owner evidence**: signed receipts and independent same-source deployment/Browser/Host acceptance required for live gate exits. Run on disposable site with explicit owner signoff, never in Production by default.

No acceptance gate may be green from a checklist, source existence, mock implementation or simulation alone.
