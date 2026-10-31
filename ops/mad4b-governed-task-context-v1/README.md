# MAD4B Governed Task Context Deepening + Cross-Batch Single Flight

Source candidate based on the current **Central Staging** `mad4b-content-hub`
plugin. This directory is a **release candidate**, not an installed update.

## Purpose

Avoid two providers performing the same original Content Job effect merely
because an event or manual request placed it into a different Hub Batch.

Five-level comparison (`policy.py`) classifies identity, original job
lifecycle, semantic inputs, provenance and source versions, dependency
receipts, and potentially executed effects. It never cancels, merges,
executes, publishes, issues a grant, or changes a job.

## Installed-code integration

The `plugin/` directory is a source snapshot of the existing live Hub,
with only these PHP files changed:

- `mad4b-content-hub.php`: imports the new source class.
- `includes/class-mad4b-hub-bulk-jobs-queue.php`: wraps enqueue, claim
  with a guarded, feature-flagged check.
- `includes/class-mad4b-hub-governed-single-flight.php`: new
  transaction-bound reservation and follower-trigger receipt.

A _Work Slot_ digest is derived from original site + tenant + brand +
Content Job + revision + stage + operation + target. Database
`UNIQUE(slot_hash)` prevents competing versions of the same job-stage
effect even if submitted content digests differ. A second digest
`work_hash` includes source/policy/capability versions, effect contract
and semantic stage input. A proven same-slot/same-work request records its
trigger as follower (not an executable task); a changed input within
the same active slot requires review, not a parallel execution.

Only the original leader may be claimed; original `finish()`
independent receipt verification remains unchanged.

## Fail-closed requirements

- Original ContentJob scope and revision must be current.
- The source-derived WorkflowOrigin must match the original job and
  its serialized integrity digest.
- Source/policy/capability snapshot digests, effect type and output
  contract must be available.
- Original `wp_mad4b_hub_semantic_flights` and
  `wp_mad4b_hub_semantic_triggers` must be InnoDB, with unique flight
  and slot indexes.
- An unreserved, still-active legacy task for this slot blocks new
  admission until independent evidence reconciliation.
- High-impact operations (publication, pricing, media rights,
  distribution, etc.) require their separate original authorization;
  this admission class does **not** authorize them.
- No fallback executor or authority lane is invented.

The feature is OFF by default. An explicit original Staging deployment
would set `MAD4B_HUB_SEMANTIC_FLIGHT_MODE=enforce` **after** the approved
site operator installs the two tables with the class's `install()`
method and certifies the old queued work. That change has **not**
been performed.

## Limitations and next gates

This is a **source candidate**. The original WordPress plugin was NOT
replaced. No tables have been created. A real concurrent MariaDB
enqueue/claim/finish test, policy-approved migration and rollback,
and a new independent readback from the actual staging runtime are
required before any enforcement deployment.

`CANCELLED`, `COMPLETED`, and uncertain-result work retain existing
receipts. New Work Slots require a new original job revision or an
independent reviewed iteration mechanism. The current policy does not
auto-reconcile older 322 tasks or the two independent Brand Context Jobs.

Workflows without a Content Job (canonical inventory, pricing, etc.)
retain their existing authorization and need their own origin-specific
work-slot contract; they are **not** incorrectly treated as Content Jobs.

Rollback: leave the source versioned, disable the feature flag, retain
the evidence tables for audit. Do **not** drop durable receipts.
