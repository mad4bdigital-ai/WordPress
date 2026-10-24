# ARE Staging — SSH Host Bridge diagnostic queue acceptance (2026-10-23)

**Scope:** `https://staging.allroyalegypt.com` (original Hostinger hosting account).  
**Status:** Read-only queue consumer *PASS*, signed receipts verified. Unified general Shell / WP-CLI / Raw SQL **NOT ENABLED**.

## Source identity

- PR: #482, branch `feature/are-staging-host-runner-developer-backend-20261023`.
- Read-only consumer source commit `bf245d6af099b872de697ab5bed31fd69b9d8517`, Git blob `c8c6bbd32f0738bb5bbebe488ca82a3edfca1c1e`.
- Preflight and session policy source commit `0c3e357a2d69f9efea02efaa4d57ee5c5796cce8`.
- SHA256 of pinned three-file candidate archive `8e49959083a1e73b0ced2cb63c8797694905f73def02a87a3553bf7fde098200`. Readback equal at VPS and Hostinger.
- Installed WordPress plugin remains `0.4.0-rc.103`; no plugin, production or ETG mutation.

## Validation on original Staging hosting over authenticated SSH

1. PHP syntax checks: two candidate PHP files PASS.
2. Standalone candidate session policy: 13 checks PASS. Policy output is *eligibility only*, with `authorized=false`.
3. Host Bridge preflight: four legacy queued read diagnostics PASS both plan and submission SHA256, exact current target fingerprint and staging environment.
4. Bound read-only consumer ran **once** on SSH as non-root Hostinger user, using only `runner.sh` hardcoded diagnostics. Worker never allows arbitrary Shell, Raw SQL or WordPress write.
5. Diagnostic results and job IDs:
   - `588281e7-13de-40f5-8d91-a470005db74e`: PHP lint, PASS, exit 0.
   - `5dd579c4-8557-4172-9eee-89a1ca29c87c`: worker health, PASS, exit 0.
   - `7a6955a0-1994-4f3e-b709-e262a2354d69`: WordPress version, PASS, exit 0.
   - `caf7b7e8-bc83-49e8-931d-f64ee6aa153d`: active plugin inventory, PASS, exit 0.
6. A fresh `mad4b/host-doctor` invocation via **ARE MCP** observed `queued=0,running=0,receipts=4,dead-letter=0,recovery-required=0`.
7. `mad4b/host-operation-receipt` via **ARE MCP** independently read the signed PHP lint receipt.
8. Using the pinned SHA256 of the dedicated read-only Ed25519 public key, separate SSH verification of **all four** receipt signatures succeeded, including job identity, environment, readback, and no mutation.
   - Receipt public-key fingerprint: `9bbf4cc2c764b0a8d1a774dd80e84988daa9f7ed856231a01a0f2cde4f64d8dc`.
   - This key certifies the **read-only diagnostic pilot only**; it must not be reused to authorize general Shell/SQL or production changes.

## Working paths

- ARE private candidate: `/home/u948953904/.mad4b-release-validation/are-staging-remediation-20261023/unified-plane-candidate/0c3e357a2d69f9efea02efaa4d57ee5c5796cce8`.
- ARE read-only runner: `/home/u948953904/.mad4b-release-validation/are-staging-remediation-20261023/ssh-developer-alternative/runner.sh`.
- ARE queue and receipts: `wp-content/mad4b-runner/bridge` under Staging.
- VPS is an SSH relay and stores the source candidate, **not** a standalone approved Shell/SQL executor.

## Open requirements before full Unified Remote Execution Plane

- Active MAD4B MCP runtime must register a signed and authoritative operator session issuer/verifier with OAuth, revocation and narrow scope.
- Introduce one governed dispatcher for `shell`, `wp-cli`, `sql.read`, `sql.write`, `sql.schema`, with session-bound exact site identity and host-enforced limits.
- Run general Shell/SQL through an independently isolated, non-root worker and staging-only DB credentials with least privilege; prove sandbox and replay resistance.
- Native Host Bridge acceptance must verify pinned detached receipt signatures, not merely read receipt JSON.
- Complete integration/negative CI tests, package and deploy a certified MAD4B release, then recheck operational MCP discovery and execution.
- Until then **DO NOT merge as production-ready or claim Shell/Raw SQL are working via MCP**.
