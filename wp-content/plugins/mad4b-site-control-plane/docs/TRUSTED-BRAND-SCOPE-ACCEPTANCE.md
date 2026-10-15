# Feature 007 — Trusted Brand Scope / Ownership Acceptance

**Status:** REVIEW-ONLY / NOT CERTIFIED. Scope: WordPress Dedicated adapter, Context Authority, ContentJobs. This document never grants WordPress, MCP, Staging, or Production authority.

## Identity and migration rules

- A brand is an opaque, persisted 32-hex identifier. A display-name change preserves identity **only** when the owner confirms the same business with matching `expected_brand_id` and `expected_revision`.
- A newly enrolled brand receives a new UUID-based identity. Matching a previous display name must **not** re-adopt sources, assets, reviews, or jobs.
- The existing site-scoped source IDs remain in storage for compatibility. When source/asset ownership is missing or belongs to another brand, reads and related mutations fail closed. This is **quarantine**, not deletion, migration, or approval.
- All source/asset transfers need an independent read-only inventory, immutable before-state, explicit owner decision, a one-time reviewed migration plan, CAS on both sides, audit, readback, and a rollback/compensation receipt. Do **not** auto-migrate on load, rename, rescan, or upgrade.
- Existing ContentJobs must be inventoried before enabling a brand-aware resolver. Old rows with blank tenant or mismatched brand must remain unchanged and inaccessible until reviewed.

## Native local preflight (run on an exact SHA checkout)

```sh
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-context-authority.php
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-context-admin-ui.php
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-jobs.php
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-deployment-mode-resolver.php
php -l wp-content/plugins/mad4b-site-control-plane/tests/trusted-brand-scope-runtime.php
php wp-content/plugins/mad4b-site-control-plane/tests/trusted-brand-scope-runtime.php
php wp-content/plugins/mad4b-site-control-plane/tests/deployment-mode-resolver-runtime.php
python3 wp-content/plugins/mad4b-site-control-plane/tests/context-authority-contract.py
```

Only record PASS after each command **actually runs** on the referenced exact SHA with exit code zero. Existing broader tests may require workflow assets, PHP extensions, and external fixtures. Missing dependencies are BLOCKED, not PASS.

## Mandatory independent acceptance matrix

| Gate | Case | PASS criterion |
| --- | --- | --- |
| I1 | Initial enrollment and exact rename | Opaque brand ID; rename requires expected ID/revision and explicit same-business confirmation |
| I2 | Re-enrollment with the same brand name | New brand ID; no old sources or approved assets readable |
| I3 | Foreign or unbound source | Hidden from active brand; collision cannot overwrite its record |
| I4 | Foreign or unbound asset | Hidden; rescan cannot reuse its approvals or mark it absent; source deletion refuses mixed ownership |
| I5 | Stale approval / policy revision | Outdated evidence rejected before any mutation or publish |
| J1 | ContentJob spoofed brand | Create rejected; no DB write |
| J2 | ContentJob list/get/events | SQL and records scoped by trusted site and brand |
| J3 | ContentJob transition race | CAS changes only the current scoped row; other brand/revision unchanged |
| J4 | Existing unscoped jobs | No automatic transfer; explicit audited migration needed |
| D1 | Missing deployment enrollment | Fail-closed; safe remediation code displayed |
| D2 | Multisite `switch_to_blog()` / restore backup | No cached identity leakage; current persisted site/blog/origin/revision rechecked |
| E1 | `local` / `development` / `staging` / `production` | Distinct environment labels, no local evidence certifying staging or production; cross-system taxonomy requires independent parity |
| M1 | MCP pairing | Registered → Discovered → Authorized → Executed → Independently Readback-Verified on the exact runtime pair |
| R1 | Provider loss, 403/429, half-complete Drive sync | No duplicate write, approval replay, or concealed partial success; independent outbox proof still required |
| P1 | Exact package and deployment | HEAD, tree, package hash, deployed Staging fingerprint identical; separate browser/host signatures |
| P2 | Performance and recovery | Measured resource budgets + tested compensation with no silent data loss |

The native tests in this PR do **not** substitute for J3, D2, E1, M1, R1, P1, or P2. Do not upgrade their states based on source review.

## Rollout / rollback

1. Inventory Site Profiles, active brand ID, source/asset brand bindings, job brand/tenant columns and external references in **read-only mode**. Record counts only in everyday UI.
2. Run native PHP fixtures and MySQL/MariaDB tests in a disposable environment; test backup restore and two concurrently active brands.
3. Apply a versioned read-only migration assessment; hold ambiguous identities in quarantine. Never infer ownership from name, `site_uuid` alone, or shared Drive folder IDs.
4. Rebuild a package from the approved exact SHA, check archive file hashes, deploy to authorized Staging only, and independently read back source/host/WordPress/MCP/browser evidence.
5. Verify operator recovery messages are non-authorizing and publication remains denied. Rollback uses exact previous package, registry snapshot and receipt verification; cross-provider compensation must be proven before enabling cross-provider writes.
6. Merge to the Feature007 hub only after approved tests; **do not merge Feature007 to master or promote Production** from this child PR.

## Known open dependencies

- Universal Operation Guard on every dispatch path and alternative Ability, not just Context Authority/ContentJobs.
- Owner-reviewed legacy source/asset/job migration implementation.
- Transactional outbox, retries/idempotency, partial-write reconciliation and recovery UX for external providers.
- Cross-system `local` receipt semantics and authoritative environment propagation from Host bootstrap.
- Independent MCP transport pairing, provider-credential authorization, WPML/Rank Math/JetEngine/WooCommerce per-site acceptance.
- Executed PHP-version, real DB, browser, package/host, and Staging evidence on the exact head.

**Acceptance decision:** Until all applicable gates have independent receipts, keep **Draft / NOT PROVEN**.

## 2026-10-10 — Cross-layer Operational Integrity follow-up

This is a separate evidence checkpoint from the original brand-isolation patch.

| Domain | Implemented on child patch | Proof still needed |
|---|---|---|
| Read-only site identity | `Site_Profile::bootstrap()` no longer persists legacy/preset data; `legacy_migration_plan()` is read-only; explicit administrator action uses audit, CAS, and pending-state quarantine | Run full PHP site-profile regression and exercise WP REST/MCP/Cron Status against actual installed plugin |
| Migration UX | Nonce-protected, typed-confirmation legacy migration form in Site Profile admin; reenrollment required for grants | Staging test with valid v1, copied origin, denied administrator, failed audit, repeated request |
| MCP namespace ownership | Resolver stores its own registered Ability instance and rejects a different live registry object; idempotent own registration allowed | Tests on target WP/MCP Adapter versions and independent discovery/read-execute |
| MCP tool exposure | `mad4b-read` and `mad4b-chatgpt` explicit allowlists gated on owned registration; default public metadata remains false | Exact server ID, role, authenticated session, discovery, run, and readback |
| Scheduling | Durable ContentJob enforces ISO-8601 with timezone offset or Z; accepted values normalized to UTC | WordPress UI, local timezones, DST transitions, and canonical date schema compatibility |
| Brand isolation | Brand/site-bound source, asset, job read/write constraints; foreign legacy lineage quarantined | Live multi-tenant DB, multisite switch, backup/restore, provider interruption |
| Asynchronous work | Existing `MAD4B_SCP_Durable_Execution` has outbox, inbox, idempotency and reconciliation primitives | No claim of complete adoption by all connectors: explicitly test external-success/local-failure and retry across workers |
| Privacy | Existing rights/data-processing evaluation surfaces | Independently prove exporter/eraser paths, audit minimization, retention/legal holds, and revocation |
| Release | Exact child SHA pinned and source changes read back | Run native PHP and DB matrix, package ZIP, inspect artifact SHA-256, Staging deploy, MCP/browser/Host acceptance, rollback rehearsal |

**Real Staging observation (read-only, before deployment):** All Royal Egypt `mad4b_tool_discover(query='deployment-mode-status')` returned zero items, and its Site Profile reported `deployment_binding_configured=false`. This is not proof the new patch failed; it is evidence that the existing live runtime does **not** yet satisfy the Dedicated pairing acceptance gate.

**Explicitly out of scope of a source-only child fix:** all 544 files in parent #258, independent cryptographic trust roots, key rotation, full provider-specific reconciliation, performance/load at scale, legal erasure decision semantics, and host/browser certification. Record them as `NOT_PROVEN`, not `PASS`.

### Extra native preflight commands

```sh
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile-admin.php
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-servers.php
php wp-content/plugins/mad4b-site-control-plane/tests/operational-integrity-local-runtime.php
php wp-content/plugins/mad4b-site-control-plane/tests/site-profile-general-distribution-runtime.php
```

These commands are instructions for the exact SHA checkout. They are not represented as executed until the runner returns a trustworthy exit-code receipt.
