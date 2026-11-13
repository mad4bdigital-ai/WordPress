# V21 — Native Source Catalog Read Bridge and Live Source Evidence

Date: 2026-11-13. **Source-only, release HOLD. No live WordPress changes.**

The existing Central class `MAD4B_SCP_Native_Source_Release_Review` was orphaned:
its source defined two read-only WordPress Abilities, but the Control Plane
neither included nor booted it. V21 wires it beside the already present
operation registry in the full governed Control Plane bootstrap. This wiring
creates **only** `mad4b/native-source-catalog-status` and
`mad4b/native-source-catalog-preflight`, both read-only, private, non-destructive.
Neither registers the write Ability `mad4b/native-source-release`, enrolls
`source.code.release`, approves a ticket, or invokes an executor.

The read bridge continues to match the exact plan's site UUID, origin,
operation, provider, Git commit, target preimage, manifest, and operation
binding. Its file path validation now rejects a *single* backslash explicitly
as well as slash/relative path attacks. An offline WordPress-like fixture
checks valid and invalid requests, read-only registration, and fail-closed
preflight with no write Provider.

## Independent current-state split

Previously the source release readiness auditor searched the **candidate Git
main plugin** for the class filename and labeled its presence as
`central_source_review_module_bootstrapped`. That could falsely remove a
live enrollment blocker after a source-only commit. The V21 audit instead
reads the **installed WordPress main plugin** and actual installed bridge
source file, requiring both exact active include/boot statements and matching
bridge file bytes. It reports candidate presence, installed static parity,
and runtime Ability readback separately. Source/live static parity is **not**
proof that the WordPress Abilities API actually registered or that first-party
Original306/313 signed enrollment is complete.

## Existing provenance retained

`control_plane_readback_provenance.py` still proves the original five-line
Original305 Job Readback insertion from the pinned historical Git preimage,
including its original candidate SHA. A second, independently pinned
three-line additive review binds the V21 read bridge; unrelated Bootstrap
edits, missing bridge boot, changed signer semantics, or source SHA drift
remain refused. Earlier provenance guards are neither disabled nor waived.

## Release status

Focused scope: 163/163 Native Source tests, 120/120 first-party dynamic
registry tests, 15/15 Original306 signed association tests, and isolated
PHP syntax checks pass. Live read-only audit remains **HOLD with 9 blockers**,
including missing enrolled first-party provider, no root-owned policy,
original306 Worker drift, and Central bridge not installed. Original313 V19
forward guard approval and independent signed receipt remain separate.

Do **not** copy the candidate to live WordPress or register a false ability
through WP-CLI. Use the existing governed first-party release authority and
read back both the installed source and actual WordPress Ability registration.
