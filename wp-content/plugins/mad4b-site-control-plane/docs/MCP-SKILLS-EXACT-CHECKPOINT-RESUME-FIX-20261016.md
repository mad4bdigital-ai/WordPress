# MAD4B: Skills checkpoint resume repair — ETG & All Royal Egypt

Scope: Staging. Reproduction after deployment of source `2ec8e22ff89c0b94ccd61924036f764b1bbc2be2` on both sites.

## Root cause (confirmed in source and site readbacks)

A managed Skills seed reconciliation handles three entries per request and durably stores `status=running`, `stage=seed_reconciliation`, `operation_id`, and `seed_cursor`, then releases its lease in `finally`. The status endpoint previously emitted `stale_running_checkpoint` whenever the lease was not held, even though release between batches is normal. Enrollment Info translated that to `replan_exact_candidate` and Fast Autopilot recognized only literal `state=running`, so a healthy pause was reported as a broken workflow. The operator then had to reason around contradictory states. This is a real source-level defect; it does **not** establish that every observed `The tool failed internally` was caused by WordPress instead of upstream MCP transport.

## Corrected behavior

- Exact current build SHA, fingerprint and manifest, a valid UUID, and an allowed persisted stage are required before recognizing a normal inter-batch pause as `awaiting_exact_resume`.
- The status offers `resume_same_operation_after_exact_authorization` and `resume_eligible_after_fresh_permission=true` as **read-only hints**. It does not execute or authorize writes, retry uncertain operations, or carry permissions from old candidate bindings.
- A held lease emits `observe_current_attempt`; an old build, missing checkpoint ID, or invalid stage remains non-resumable and fail-closed.
- Enrollment Info relays the source status's next action, stage, operation ID and cursor instead of inventing a generic replan instruction.
- Fast Autopilot recognizes `awaiting_exact_resume` only with current exact identity, allowed resume policy, and a stored operation ID. It continues to show Write as a separate owner-review gate and browser/provider certification as independent acceptance requirements.

## Regression evidence

- `managed-skills-checkpoint-resume-runtime.php`: normal 3/11 or 6/11 batch pause, stale build, active lock, malformed checkpoint, completed operation.
- `fast-staging-autopilot-runtime.php`: same-operation routing for the new paused state; old-build state denied.
- `remote-operation-parity-contract.py`: preserves exact identity checks and enforces source-level non-bypass controls.
- `managed-skills-heartbeat-runtime.php` and `enrollment-dispatch-runtime.php`: lease fencing and uncertain-child handling.

## Release boundary

This change is not active on either WordPress site until a fresh exact-head ZIP is installed and its source identity is confirmed. The current live checkpoints must not be copied to a different candidate build. After any new install, reconcile Managed Skills for the new exact SHA, then use the independent write-only authority handshake with the site's owner-review digest. The scope expressly excludes Production, Developer, Breakglass, generic approval bypass and broad plugin mutation.

A tool-layer OpenAI safety refusal is not a normal transient error and must not be routed around through an alternate executor.
