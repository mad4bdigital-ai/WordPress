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
5. Two additional defense-in-depth corrections were NOT applied: ensure Git source equals deployed file target, and independently recompute changed_count from the immutable source/preimage SHAs. The attempted source edit was blocked by tool safety checks. This candidate is not deployable until reviewed and fixed through an authorized route.
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
