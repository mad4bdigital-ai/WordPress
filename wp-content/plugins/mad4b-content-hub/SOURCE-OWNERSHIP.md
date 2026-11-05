# Content Hub — WPML Tour Intake Revision Repair

Source enrolled from the active MAD4B Central Staging plugin at `tours.dev.mad4b.com`. The 60 runtime files are tracked under their real plugin owner, and `source-files.sha256` checks the exact staging source. The source is not Production-authorized by the existence of this file or CI.

## Root cause (2026-11-05)
Creation of 54 missing-language `tours-and-activities` Content Jobs automatically reserved semantic-flight work at job Revision 1 while state `NEW`. Immediately changing the Job to `QUEUED` produced Revision 2. The original work's source-origin proof then failed with `mad4b_hub_flight_origin_scope_or_revision_mismatch`; a repeated trigger returned HTTP 409 (`overlapping_iteration_requires_review`). Workers correctly refused stale work.

## Bounded correction
`MAD4B_Hub_Trigger_Bus::on_created` now defers **only** the exact `content_type=translation`, `target_post_type=tours-and-activities`, `wpml-tour:<source>:<language>` (AR/FR/DE) NEW/Revision-1 family. The authoritative `QUEUED` transition reserves new work on the real Revision 2; other Job types and the Semantic Single-Flight enforcement remain unchanged.

Existing 54 obsolete reservations were reconciled on Staging with exact identity checks, zero attempt/lease/result proofs, native batch cancellation, immutable semantic-trigger supersession receipts, and native Trigger Bus rescheduling. The original `runtime_resolve` operation completed for all 54 on Revision 2. No model writing or publication is claimed.

## Readback
- 54/54 active semantic-flight reservations at Revision 2.
- 0 stale Revision-1 active reservations.
- 54/54 `runtime_resolve` completed with native VPS Runner receipts.
- 54 `stage_work` tasks remain `WAITING_EXECUTOR` with `mcp_client` assignment.
- 54 `preexecution_supersede` audit receipts.
- The 54 Content Jobs remain `QUEUED` (AR 22 / FR 16 / DE 16).

No execution permissions, authority boundaries, content evidence or publication gates were bypassed. The semantic-flight policy stays enabled. The remaining problem is the **real** Content Jobs writer handoff and verified editorial dependencies, not a phantom lock.

## Reproduction / tests
`php tests/wpml-tour-intake-trigger-contract.php` covers NEW and negative states across languages. Production remains unchanged. The source is read-only in CI; deployment requires explicit separate Staging/Production rollout governance.
