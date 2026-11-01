# Unlinked Content Hub work contracts — v1

**Scope:** The 135 current tasks with empty `content_job_id` in Central Staging.
No legacy task is rewritten, cancelled or attached to a fabricated Content Job.

## Read-only live inventory

| Original source | Rows | Contract | Enforced decision before provenance |
|---|---:|---|---|
| Legacy tasks without source-derived Workflow Origin | 132 | `legacy_unverified` | `RECONCILE_ORIGINAL_ORIGIN` |
| Canonical Gap / product_data | 2 | `product_data/canonical_gap` | Original source + read-only effect evidence required |
| Platform QA diagnostic | 1 | `qa_diagnostic` | `DIAGNOSTIC_NO_ORIGINAL_PROVENANCE` |
| Verified integration/provider handoff (future) | 0 | `integration_operation` | `VERIFIED_PROVIDER_HANDOFF_RECEIPT_REQUIRED` |

Rows may change after this read. This is a classification of source,
NOT a declaration that any of these tasks may be silently deduplicated.

## Canonical Gap contract

The authoritative *gap* and *canonical entity* rows must agree on
company, source Hub, type, language, gap code, and unresolved state.
Provider and dataset are re-read from the original canonical snapshot.
The work context derives its source fingerprint from persisted rows,
not from a caller-provided `source_snapshot_sha256`. The original
`MAD4B_Hub_Workflow_Origin::claim_source_guard()` is required.

The Work Slot includes site, source company, exact Gap and Canonical ID,
stage, operation and target. The semantic Work Hash additionally includes
authoritative source fingerprint, policy/capability snapshots, input,
provider/dataset and output contract. A changed semantic input in the
same slot is a collision requiring reviewed iteration, not a second run.

Only explicitly verified read-only source inspections may be reserved.
Pricing write, publication, inventory mutation, changed company,
unverified origin, unregistered provider, and any untrusted integration
are stopped. Reservation does **not** confer provider permission.

## Integration handoff

The original integration contract may identify provider and record, but
that is not proof of a live provider receipt. Source-verified=false by
design. Before a durable, independently verified provider source
snapshot/receipt contract exists, it is classified and blocked from
automatic deduplication. Do not infer a company from its target string.

## QA and legacy

Diagnostics without durable original source and legacy tasks without
WorkflowOrigin are explicitly classified but **not** coerced into
Canonical Gaps or Content Jobs. They may require independent review, or
an original source registration with a new signed/verified contract.
No platform-origin authority is inferred from a Batch key.

## Transaction and dependency behavior

The candidate uses `UNIQUE(slot_hash)` and `PRIMARY KEY(work_hash)` in
InnoDB. Enqueue creates leader+flight+first trigger inside one original
transaction. A second identical request with a single independent item
may record a follower trigger *after rollback* of the failed second
batch. Multi-item or DAG-dependent follower requests remain blocked to
avoid partial acceptance. The original claim path must revalidate exact
leader and current source. Completion retains original receipt verifier.

The plan is fail-closed under unknown effect or missing evidence. It
requires original release approval and concurrent MariaDB acceptance,
not merely passing the isolated tests.

**No Staging or Production deployment** was performed by this source
change. No new semantic-flight tables exist at the observed live readback.
