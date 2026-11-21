# Dynamic Native Source Publisher — source-only candidate

**Not enrolled. Not activated. No live release authorized.**

## Architecture

- Operations are discovered from SHA-pinned Original306/313 provider manifests, not from an executable operation-name allowlist.
- Each manifest declares its own operation ID, provider, approval Ability, independent verifier, command and first-party dependency digests.
- The candidate builder discovers all JSON operation descriptors under catalog/operations and emits one unsigned candidate manifest per operation under catalog/generated. The existing source.code.release definition is one example, not a special case in the executor.
- Target company, environment, Site UUID, origin and permitted file paths come from a separate root-owned per-site policy. No environment or company is given automatic write access.
- The original CAS plan retains its immutable plan SHA256. The selected operation is recorded in a separate source binding digest over the core plan and manifest. Signed Master Admin approval binds provider, Ability, plan and manifest/binding digests; the first-party Original312 claim binds the enrolled operation.
- Central PHP review is a generic, read-only module for canonical operation/site IDs and file preimage comparison. The module is deliberately NOT mounted in protected Control Plane bootstraps until approved.

## Tests

- 22/22 Python focused tests passed with two different operations and site scopes, original CAS verification, signed approvals, cross-company denial, original first-party Registry validation and dynamic manifest generation.
- PHP read-only acceptance passed for two operator/site combinations.
- Full repository suite: 354 tests, 8 failures and 14 errors, exactly the same 22 failing test labels present on the parent baseline.

## Unresolved prerequisites / explicit non-promotion

1. The provider is not enrolled in Original306/313 or installed on Central.
2. No trusted enabled v2 source policy has been enrolled.
3. No signed Master Admin approval, Broker release grant or independently signed Original313 receipt is available.
4. Central PHP review code is source-only and not activated or exported to live MCP tools.
5. Source-only defense-in-depth corrections now require exact Git-source/target path equivalence and independently recompute changed_count from immutable source/preimage SHA256s. Both negative fixture probes must DENY before any release. These corrections are not installed on the live executor and do not clear the remaining authority gates.
6. Unified Date Apply remains a browser-tested but uninstalled JavaScript candidate on Tours.dev.

Never self-issue a receipt, use test keys, or directly patch the live site to bypass a denied publisher.

## Additional fail-closed release audit

The separate audit_release_readiness.py source-only checker discovers all
candidate operations from the generated catalog. The executable returns
nonzero HOLD until source/target equivalence and derived change-count
semantics, first-party provider registration, enabled root policy, and
installed Central review are independently satisfied. It never creates
authority, calls an enrolled original worker, or issues a signed receipt.
See RELEASE_READINESS_AUDIT_20261112.md.

Additional audit tests bring focused Python coverage to 26/26. Full
repository suite has 358 tests, with the same 22 failures present on the
parent baseline and no newly failing test labels.

## Original306/313 compatibility update

The read-only audit reports eight blockers including CAS-envelope mismatch,
operation/plan ambiguity across providers sharing a plan contract, a
Production evidence environment mismatch and unregistered authority.
All checks use ephemeral fixtures or installed read-only validators.
No authority was created. See RELEASE_READINESS_AUDIT_20261112.md.

## Signed association source contract

A separate, verifiable Plan-to-Operation Association now exists in
operation_plan_association.py, with ephemeral-key tests and successful
compatibility against the installed read-only Original306/313 plan validator.
It does NOT mutate the core plan, issue authority or integrate with the
currently installed worker. The release gate requires a trusted first-party
worker association hook before registering any new operation. See
PLAN_OPERATION_ASSOCIATION_CONTRACT_20261112.md.

## Source-only associated indexing adapter

Added associated_worker_index.py with two-provider tests and a fail-closed
index for source-family operations. This is a reviewable additive candidate,
not an installed Original306 change. A source-versus-installed worker drift
was verified, and the worker must be reconciled before approved enrollment.

## Associated Original306 index and source drift guard

A read-only, non-executing associated_worker_index.py candidate has been
tested with two enrolled provider manifests and the original first-party
plan validator. It enforces one signed plan association and denies fallback to
another provider, while leaving unrelated legacy plan contracts unchanged.
The full suite test module imports first-party code under private aliases to
avoid contaminating other Registry tests. The readiness audit now checks
source-versus-installed Original306 worker digests before promoting any
integration; they differ, so it remains HOLD.

## Legacy plan preservation

Read-only inventory of Original306 confirms 16 existing CAS plans and
15 dynamically detected contract collisions with source-family enrollment.
The existing first-party worker still indexes 16/16. The source-only
legacy_plan_migration_preview.py validates original attribution for all
15 affected plans and reports 0 unresolved, with no signed associations
or modifications. Runtime job/claim state is not yet certified.
LEGACY_PLAN_MIGRATION_20261112.md defines migration and rollback conditions.

## Original305/312 historical evidence

Read-only original_finish_readonly_audit.py verifies historical Broker-signed
FINISH records against the live Original306 command index and protected
public key: 10 valid signed attempts, 6 commands without comparable proof.
Current Job/Claim and fresh Original312 are still unverified; the release
gate explicitly holds until first-party authenticated readback is completed.

## Historical original305 Grant readback and lifecycle classifier

The first-party Broker public key verifies 13 historical Grants covering
only 10 unique company-code-release plans; all are expired. Five plans have
no matching Grant in the inspected inventory, and two have repeated records.
Original Job/Claim statuses remain unverified. Added read-only Grant audit
and a source-only, fail-closed original Job/Claim lifecycle preview; neither
creates current authorization or historical replay.

## Current Original305/306 WordPress read-only Ability candidate

An unmounted read-only WordPress Ability is now present in
class-mad4b-scp-original-job-readback.php, with bounded digest lists,
source site/company scope, administrator permissions, error and ambiguous-Job
blocking. PHP and Python source tests passed. No live Job read or enrollment
was performed. See ORIGINAL305_JOB_READBACK_ABILITY_CANDIDATE_20261112.md.

## Redacted WP Job/Claim to lifecycle contract

A separate compact_job_readback_classifier.py consumes the protected
read-only Job output without exposing Job IDs or Grants. It checks source
scope, exact command, readback age, Claim flags and denied authority states.
Every result is HOLD. The release gate differentiates the source-only
classifier from the still-unmounted live WordPress Ability.

## 12 November isolated source continuation

- Control Plane source now requires and boots the bounded `mad4b/original-job-claim-readback` Ability after its foreign REST fastpath, with privileged role, redaction and no authority creation. Installed site is unchanged.
- The publisher source now denies Git-source/target substitutions and independently validates changed_count. The candidate manifest is regenerated from SHA-pinned source, not enrolled.
- Original312 current signatures, Original313 envelope compatibility, signed legacy associations, Original306 worker parity, and canonical Control Plane deployment remain separate gates. Do not interpret source-level boot as live Job/Claim evidence.
