# MCP-managed WordPress Staging environment synchronization

**Status:** Implementation in Feature 007 Hub branch; **no live Host execution or production certification**.

## Purpose and responsibility

The supported Site Profile mode `host_managed` is a reviewed, non-Production request for a trusted Host Runner to set `WP_ENVIRONMENT_TYPE=staging` before WordPress bootstraps. Neither `mad4b/site-profile-status` nor the WordPress connector can directly rewrite `wp-config.php` during a web request. The WordPress `wp_get_environment_type()` value is cached for the current request. A separate, fresh WordPress request must verify the actual Staging value.

The implementation **reuses** the existing WordPress MCP Host Bridge: `mad4b/host-operation-capabilities`, `mad4b/host-operation-plan`, `mad4b/host-operation-apply`, `mad4b/host-operation-status`, `mad4b/host-operation-receipt`, `mad4b/host-doctor`, plus the exact `tools/mad4b_host_runner.py` Host Runner. It adds **only** `wordpress_environment_sync` and `wordpress_environment_rollback`; no arbitrary command, path, PHP code, unbounded wp-config editing or generic shell capability.

## Non-negotiable trust prerequisites

1. Run a certified, pinned Staging Control Plane package from the intended exact GitHub source revision. Do not use a release channel whose candidate is older than the installed runtime.
2. Verify the exact origin and site UUID, confirmed `host_managed` selection, and a **host-only unique** `MAD4B_SCP_DEPLOYMENT_BINDING` bound to that saved Site Profile. The raw binding is never returned through MCP or stored in WordPress.
3. Independently enroll the trusted Host Runner profile for the **same Staging site**, with pinned `expected_runner_sha256`, HMAC integrity key, `allowed_operations` containing the two new operations, and a `host_environment_backup_root` provisioned out of the WordPress root by the Host operator. This directory **must have mode 0700 (or stricter), no symlink ancestors and the same OS owner as the runner**; raw wp-config backups must never go to `wp-content` or any web-served area. No Host Runner profile is created or granted by saving a WordPress setting.
4. Target wp-config must be a regular file in the verified WordPress root **or exactly one directory above it**, matching WordPress core's lookup precedence: the parent alternative is only eligible when the local config is absent and the parent contains no `wp-settings.php`. No symlinks, user-supplied paths, or arbitrary traversal are permitted. The file must be owned by the enrolled Host Runner, within the size limit, with no existing `WP_ENVIRONMENT_TYPE` and one canonical `require_once ABSPATH . 'wp-settings.php';`. An explicit WordPress environment **always wins**.
5. Exact write authorization and owner approval are required. The Host Runner profile environment must be `staging`; Production is not eligible. No Developer/Breakglass/Production authority is supplied by this capability.

## MCP flow

**Step 1 — discover and plan.** Read `mad4b/host-operation-capabilities` and `mad4b/host-doctor`. Generate a bounded read-only plan:

```json
{
  "operation_id": "wordpress_environment_sync",
  "runner_profile_id": "ENROLLED_STAGING_RUNNER_PROFILE_ID",
  "arguments": {
    "reason": "Align exact Staging WordPress bootstrap with the enrolled Site Profile"
  }
}
```

The Bridge derives and hashes the exact source site UUID, Staging environment, deployment binding digest, profile revision/digest, and current wp-config SHA. It returns a nested execution plan with fixed target `staging`. No user-supplied config path, snippet or environment value is accepted.

**Step 2 — authorize and enqueue.** After reviewing that exact plan and owner approval, call `mad4b/host-operation-apply` with the unchanged plan, exact `server_id`, `approval_ref`, governance ticket if required, fresh `job_id`, and fresh `idempotency_key`. The returned `queued=true` means **only the WordPress spool write succeeded**, **not** that wp-config changed. A second tab or changed Site Profile/profile revision/binding invalidates the plan before enqueue.

**Step 3 — Host Runner executes.** The enrolled service consumes `consume-bridge-spool --profile <protected-runner-profile> --limit 1`. It independently checks the signed/HMAC-verified plan, exact Staging runner profile, wp-config SHA, safe operation registry, approval lineage, and private snapshot zone. It copies a 0600 secret backup to the host-private directory, journals intent, inserts exactly one fixed Staging define immediately before WordPress settings bootstrap, preserves file permissions, performs atomic replacement, reads back the resulting file hash, and rolls back upon failed postcondition or receipt persistence. No request-provided bytes are inserted into PHP configuration.

**Step 4 — observe.** Check `mad4b/host-operation-status` then `mad4b/host-operation-receipt`. A successful Host file receipt sets `host_file_readback_verified=true` and `fresh_wordpress_bootstrap_verified=false`: it is deliberately **not** a release acceptance. After a new PHP/WordPress request, read `mad4b/site-profile-status` and verify:
- WordPress reports **explicit** `staging` from `wp_get_environment_type()`;
- site UUID, canonical origin, deployment binding, and effective Staging still match;
- `environment_sync_state=host_aligned` on the new build;
- subsequent MCP grants, Browser evidence and operational acceptance remain independently gated.

## Independent signed Host receipt and fresh WordPress bootstrap acceptance

The WordPress Host Bridge spool is **not** an independent Host trust root, even when its JSON claims a successful file readback. A writable WordPress directory cannot self-attest a Host mutation. For this reason the exact new `mad4b/host-environment-sync-verification` read ability **fails closed** unless it can verify a separate Host Runner Ed25519 signature over a bounded receipt payload. It does not return any Host secret and it cannot mutate the site.

During one-time Host enrollment, provision a private Ed25519 PEM signing key **outside the WordPress root** with mode 0600 and trusted Host Runner ownership. Supply its location through the protected Host Runner profile's `host_environment_receipt_signing_key_file`, and supply its raw 32-byte public key as Base64 in `host_environment_receipt_signing_public_key_b64`. The Host Runner compares private/public before enabling these operations and requires the `cryptography` Ed25519 package. Do not provide the private key or PEM bytes through MCP/WordPress.

Pin the matching **public key only** on the target WordPress Host before its bootstrap using the Host-managed constant `MAD4B_SCP_HOST_ENVIRONMENT_RECEIPT_PUBLIC_KEY_B64`. PHP's Sodium extension must be available. No server-supplied key, key selected by the caller, unsigned receipt, or mismatched signer can satisfy the verification. Key rotation needs a new Host enrollment and review of the resulting exact trust binding; do not reuse old receipts as evidence for a new key.

The Host Runner signs `mad4b.host-environment-receipt-payload.v1`, covering the exact job ID, Host site UUID, Staging target and target fingerprint, operation, exact plan hash, authority and approval references, Runner source hash, Host completion timestamp, mutation/readback state, wp-config **before- and after-state hashes**, Site Profile digest/revision, unique Host binding digest, and file-readback boolean. The same signing protocol applies to the rollback execution receipt, while the Runner refuses to restore an earlier configuration unless its original source receipt signature authenticates against the Host-pinned public key. The signature metadata uses `mad4b.host-environment-ed25519-attestation.v1` with Ed25519 and the pinned public-key fingerprint. It cannot be reconstructed merely by changing a WordPress spool JSON file.

A new WordPress request calls `mad4b/host-environment-sync-verification` with exactly `{"job_id":"HOST_JOB_UUID"}`. The readback requires **all**: valid Host public-key signature, exact Staging job/site, authorized readback receipt, unchanged wp-config after-hash, the same bound Site Profile digest/revision and deployment binding, explicit WordPress `staging` with `host_aligned`, and a PHP request started *after* the Host receipt completion. A matching SHA alone is not enough. A pass yields `VERIFIED_STAGING_HOST_ALIGNED`, but **`release_certified=false`** remains required because Browser, Skills, and other staging gates are separate.

Do not make the WordPress process sign a receipt, and never copy the Host signing key into `wp-config.php`, WordPress options, WordPress logs, GitHub, or a public web root. Without enrolled Host keys, external binding, verified receipt, or a fresh bootstrap, report `BLOCKED` explicitly.

## Reversible rollback over MCP

If the original Host Runner execution has a persisted `PASS` receipt and the current wp-config SHA **exactly equals that receipt's after-state**, plan a `wordpress_environment_rollback` operation using **only** `source_job_id` and bounded `reason`. The Bridge binds the source receipt SHA, original before-state and current after-state. The Host Runner must prove the original successful receipt and its private immutable backup, snapshot the current file again, perform an exact atomic restore and produce a new durable rollback receipt. Re-run WordPress bootstrap/readback; do not claim immediate cached WordPress state changes. Changes since the original sync cause a **hard stop and manual reconciliation**, never a blind overwrite.

## Negative acceptance cases

Attempt each in an isolated disposable Host only: Profile Only mode; missing or cloned binding; explicit Production or other Host declaration; stale profile revision; stale wp-config fingerprint; manipulated plan fields or approval; unsupported PHP bootstrap; existing WP environment define; caller-provided code/path/URL; symlinked config/backup zone; publicly accessible backup directory; absent Host runner profile; wrong runner source hash; replayed job; deleted/corrupted receipt or backup; insufficient file permissions; failed atomic write; failed receipt persistence; repeated rollback; and downgrade of the installed Staging package. Each must fail closed, with a specific reason, without publishing success or relaxing an authorization gate.

## Current deployment limitations

A WordPress MCP connector by itself **cannot initialize host-root privileges or create a trusted Runner enrollment**. That bootstrap is a separate one-time Host authorization. The installed All Royal Staging rc.96 does not contain this branch's new operation registry/executor. This code change does not configure its live host and must not be used to claim Production readiness.
