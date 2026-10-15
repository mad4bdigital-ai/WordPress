# MAD4B Staging Write Bootstrap — acceptance and operator handoff

**Scope:** `spec/007-competitive-experience-20261006` / PR #258.  
**Sites:** ETG Staging and All Royal Egypt Staging, individually.  
**Production:** read-only; never mutate or promote based on this document.

## Security boundaries

- `mad4b/staging-write-owner-review` is a bounded, read-only plan review. It yields a server-computed `review_digest`; discovery is **not authorization**.
- `mad4b/staging-write-owner-attest` records short-lived intent for one explicitly enrolled Staging owner, requires normal ChatGPT CIMD OAuth with authority Step-Up, and **does not create grants, tickets, a release certificate or deployment binding**.
- `owner_intent_sha256` is the site-secret-HMAC-bound receipt field required by `mad4b/staging-write-authority-convergence-apply`. Claims are atomic and single-use (including if subsequent work fails).
- The owner intent binds the **entire exact current plan identity**: site UUID, Site Profile revision/digest, source SHA, build fingerprint, package manifest digest, artifact identity and plan SHA.
- The only pre-Write transport bootstrap exception is the **owner intent attestation** Ability. All normal writes still require the governed Write authority.
- Production, Developer, Breakglass, arbitrary provider writes and cross-site reuse remain ineligible.
- The selected-head release is gated by **four source-exact successful GitHub workflows**: `mad4b-control-plane-package.yml`, `mad4b-release-verdict.yml`, `mad4b-admin-performance.yml` and `mad4b-runtime-integration.yml`, plus the existing GitHub attestation and required reviewer policy.

## Acceptance tests in source

| Test | Expected scope | When PASS can be claimed |
|---|---|---|
| `staging-write-owner-intent-runtime.php` | Exact owner, site, source, HMAC, expiry, replay, audit uncertainty, Production denial | CI result succeeded |
| `staging-write-owner-transport-runtime.php` | Only exact owner-attestation bypasses stale Write candidate | CI result succeeded |
| `enrollment-dispatch-runtime.php` | Strict owner catalog exception, schema/registration drift, OAuth denials | CI result succeeded |
| `staging-write-authority-postcondition-runtime.php` | Reject partial grants, candidate drift, unreviewed grants and wrong site | CI result succeeded |
| `staging-write-catalog-parity-contract.py` | Shared owner policy across discovery and transport | CI result succeeded |
| `staging-owner-release-gate-contract.py` | Exact-head publisher prerequisites and attestation policy | CI result succeeded |
| `runtime-owner-bootstrap-integration.php` | Actual WordPress Ability registration, missing-owner denial, no state mutation | Runtime Integration succeeds |

A hermetic PASS does not certify a deployed MCP request or a signed artifact.

## Required per-site operational sequence

1. Read current installed source and Site Profile identity. Compare to **certified** package SHA; never automatically apply a Draft PR.
2. Review fresh Write Convergence Plan; require `ready_to_apply=true` with `hard_blockers=[]`.
3. Read the owner review, obtain exact `review_digest`, and obtain an independently authorized owner decision. Owner attestation lasts ten minutes, requires one explicitly enrolled user, and cannot be replayed.
4. Execute **only** the reviewed Staging Write convergence with exact plan inputs, one-time owner intent receipt, OAuth Step-Up, current fingerprint and the exact confirmation. Any error requires independent reconciliation; no blind retry.
5. Read Write Authority and candidate binding **in a new MCP request**, compare current source/profile and granted inventory.
6. Plan the native Deployment Binding for that specific site, obtain its **separate** approval ticket and owner decision, apply, and verify in a new request. Owner Write intent is **not** a native-binding approval ticket.
7. Confirm Operational Integrity, then review quarantined legacy Context source ownership and source folders. Google provider revocation remains a separate lifecycle, especially on ETG.
8. Perform the real Browser/Context and release acceptance. Keep Production untouched until independent evidence is signed.

## Explicit pending gates

A source commit, a Draft PR, or queued GitHub Actions is **not** a passing test. As of the authoring session, signed exact-head CI acceptance and Staging installation have not been verified. Verify fresh state before starting any operation because a separate deployment may have changed the sites.
