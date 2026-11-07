# PR #767 Original WP-CLI Broker — 305/306 checkpoint (7 Nov 2026)

- Source PR: https://github.com/mad4bdigital-ai/WordPress/pull/767
- Exact upstream commit incorporated by merge parent: `27129514673d11a677ca917926ba9595656b19c3`
- Original immutable VPS evidence:
  `/opt/mad4b-company-provisioning/reports/wpcli-signed-priority-20261107/checkpoint-schema-1e7c478ad2d10dff16c8fcca657f2706-20261107T002231Z.json`
- SHA-256 verified from VPS original file: `4c7f0f1c6d9a888c1a73b122b535f22e29790170f35a7d02b331b1b3df13580d`
- Evidence contract: `mad4b.schema313-fresh-job-live-evidence.v1`; observed `2026-11-07T00:22:31.553478+00:00`.
- Company: `wayfatours`. Operation: **read-only** `schema.readback`. Owner-issued Job `1e7c478ad2d10dff16c8fcca657f2706`.

## Exact verified boundaries

| Step | Evidence in original checkpoint | Verdict |
| --- | --- | --- |
| 305 — original owner-created Job | Central Job exists and is accepted | PROVEN |
| 306 — original Broker Claim | `original_305_306_claimed=true`; `BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY` | PROVEN |
| Host reservation | `original_host_artifacts.host_reservation=false` | **NOT PROVEN** |
| 312 — signed host receipt | `original_host_artifacts.host_receipt=false` | **NOT PROVEN** |
| Independent 313 | `original_313_certificate_verified=false` | **NOT PROVEN** |

At capture, only **192 seconds** remained for the original Job, below the original **360-second** safe new-host execution window. The checkpoint is **not** a certificate, signed receipt, or permission to replay an expired Job. Broker regressions reported **11/11 PASS** and no new owner grants or tour booking/content rate mutations. It records the exact staged status at capture, not current Job state.

## Code integration policy

The original PR #767 source preserves the 45-second signature freshness by performing enrolled dynamic WordPress Actor preflight **before** creating the signed heartbeat or claim, without bypassing identity, host scope, existing two-slot guard or expiry checks. The read-only WP-CLI priority probe only defers optional observer startups; its failed database read is not execution authority. All source files remain protected by the 9-entry original host SHA manifest. GitHub CI uses a fail-closed import shim for the VPS-only enrolled actor library, rather than importing any live credentials or providing fake execution.

A **new owner-authenticated Job** with full 305 → 306 → 312 → independently signed 313 readback is still required for end-to-end certification. The merged PR stays Draft until this and exact-head CI are independently verified.
