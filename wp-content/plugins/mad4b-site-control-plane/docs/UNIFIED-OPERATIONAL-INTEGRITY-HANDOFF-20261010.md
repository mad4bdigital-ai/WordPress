# MAD4B — Unified Operational Integrity: Source Remediation & Acceptance Ledger

2026-10-10 | child WordPress PR #374 → parent PR #258 | **DRAFT; DO NOT DEPLOY TO PRODUCTION**

## Scope and boundary

This deliverable strengthens the existing Site Control Plane plugin. It does **not**
create an independent WordPress plugin or grant capabilities. It covers the WordPress
Dedicated mode; portable parent Context Authority PR #8483 and child #8484 continue to
define the other three platform operating modes. No authorizing inference may cross
these mode boundaries.

## Implemented (source and synthetic test fixtures)

1. Identity: stable opaque brand identity, explicit same-brand rename with exact
   revision, refusal to adopt source/asset lineage from another brand or site.
2. Read-only Site Profile: bootstrap no longer commits legacy/preset enrollment
   during Status, MCP, REST or Cron inspection. Eligible legacy identity is described
   as a **plan**, not applied.
3. Explicit migration: administrator-only, nonce/typed-confirmation UI, CAS,
   append-only audit, complete pending mutation recovery metadata and profile digest.
   The existing recovery planner may distinguish a committed audit from absent
   evidence and reconcile the exact pending generation. No migrated authority grants.
4. Owned MCP resolver: registration instance/metadata checks, reject namespace
   collisions, custom read/ChatGPT server projection only; meta.mcp.public stays false.
   Registration is not a runtime transport certification.
5. Shared integrity scope fence (`MAD4B_SCP_Operational_Integrity`): trusted
   tenant/site/brand/blog/network/environment, Site/Brand revisions and actor identity.
   Re-resolve on each read, pre-commit exact fingerprint checks and reauthorize
   mutations with current `MAD4B_SCP_Policy::can_mutate()`.
6. ContentJob schema reads and CAS writes filter tenant, site and brand. A site
   rename or scope change is not an authorization to mutate another tenant's row.
7. ContentJob write preflight requires transactional, read-your-writes storage
   (ContentJob, event, audit event/head tables); failed START TRANSACTION blocks writes.
8. Lost COMMIT or ROLLBACK acknowledgement yields an **uncertain** outcome,
   reconciliation-required, original job ID/revision, and no blind retry.
   Generic SQL/PHP error details are not exposed through WP_Error payloads.
9. Scheduling: timestamp must be strict ISO 8601 with an explicit timezone offset,
   normalized to UTC. Natural-language dates are handled upstream.
10. Local synthetic fault matrices: missing BEGIN, nontransactional tables, unknown
    COMMIT/ROLLBACK, policy revocation, brand-revision drift, actor/blog mismatch,
    Ability collision and timezone rejection.

## Native acceptance (required, not represented as executed)

From a local checkout pinned to the exact child HEAD:

```sh
python3 tools/run_f007_exact_head_local_acceptance.py --expected-head <40-char-exact-head>
```

The runner checks `git rev-parse HEAD` **before** any unit/contract command and
exposes suite status, not raw stderr. It checks PHP syntax, site lifecycle, resolver,
brand isolation and transaction fault fixtures. Its `native_gate=PASS` is **not**
a Staging, browser, MCP, external-provider or Production release pass.

For the portable seed, separately run
`reference-seeds/context-authority-business-profile-v1/tools/run_offline_acceptance.py`
from a distinct exact-head checkout using its `--expected-head` parameter;
for native Node/Host receipt semantics pin child PR #8484 and then perform the
explicit WordPress/core dependency preflight. Never combine results with
different HEADs.

### Independent Staging read-only observation

`mad4b_session_safe_diagnostics` for All Royal Egypt reported an effective Staging
environment, runtime `0.4.0-rc.96`, source commit
`b9d7133b2ef07eb238269e8725e5062bb1c2e876`, healthy *session-safe*
identity/write diagnostics and `mcp_adapter_version=0.7.0`.
That source is not this patch and the diagnostic explicitly defers deeper checks.
One observed request reported ~4.2 seconds and 428 DB queries; this is **not**
a certified benchmark or a proven regression without a comparable baseline.

`mad4b_tool_discover(query="deployment-mode-status")` returned zero entries
before installing this patch; `mad4b_operation_discover(query="content-job")`
also returned zero entries. Current Site Profile readback showed
`deployment_binding_configured=false`. Treat them as blockers to a real
WordPress Dedicated MCP conformance verdict, not proof the uninstalled patch failed.

## Open work — MUST NOT be called Done

- Full native suite and a real InnoDB transactional integration with killed
  connection at COMMIT/ROLLBACK, including verification of no duplicate effects.
- Exact provider outbox/inbox/idempotency/reconciliation across Google Drive and
  other external connectors; state after remote-success/local-failure.
- Cross-request OAuth user/grant revocation, NHI identity, key rotation, independent
  signed attestations and nonce replay with a real Host verifier.
- MCP Adapter installed exact version and server ID; capability Registration →
  Discovery → Permission denial → Execution → Readback, including tool collision.
- Multiworker/Cron/Redis/Object Cache/Multisite stress, backup epoch restoration,
  schema-upgrade backward compatibility, memory and DB-query baseline regression.
- WordPress privacy exporter/eraser plus legal holds and retention boundary;
  redaction of sensitive log payloads during long-running operations.
- Full PR #258 (544 changed files) regression and boot/load-order matrix, PHP,
  WordPress/MariaDB version support, ZIP binary hash, browser and rollback acceptance.
- Reconcile production-vs-staging `WP_ENVIRONMENT_TYPE` only through governed
  Host bootstrap; no automatic environment changes or fabricated Deployment Binding.
- A certified immutable source HEAD, plugin ZIP, Host receipt, Staging acceptance
  and independent operator decision must precede any release.

## Release decision

`SOURCE_IMPLEMENTED / NATIVE_NOT_EXECUTED / LIVE_MCP_NOT_ACCEPTED /`
`STAGING_PATCH_NOT_DEPLOYED / RELEASE_BLOCKED`.

PRs #258/#374 and #8483/#8484 remain Draft. This ledger is non-authorizing.
