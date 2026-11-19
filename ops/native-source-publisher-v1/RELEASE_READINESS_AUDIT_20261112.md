# Native Source Publisher: release readiness audit

Current state: **HOLD**, read-only candidate. This audit is a safety check, not a
publisher, signer, original worker, or signed release receipt.

Run from the WordPress Git worktree:

    PYTHONDONTWRITEBYTECODE=1 python3 ops/native-source-publisher-v1/audit_release_readiness.py

The command returns exit code 2 while any blocking condition is observed.
The executable does not open a write transport or create an approval. All
file-read source and count mutation probes use temporary Git files and
in-memory plans, never the installed WordPress code or database.

Dynamic discovery: the audit reads every candidate descriptor under
catalog/generated. It reports unregistered operations by comparing IDs with
the installed Original306/313 index. It does not hardcode the name of the
sample source operation. Index presence is not proof of provider authenticity;
first-party independent enrollment and signed Original313 readback remain
separate requirements.

The two security probes, observed 2026-11-12:

- A plan may use the immutable Git content from a different relative source
  path while targeting an otherwise allowed file. This is permitted by the
  current core semantics when the target is explicitly in the policy. MAD4B's
  stricter chosen source-publisher policy requires matching relative paths
  unless a separately reviewed source-to-target mapping is explicitly
  authorized by a provider.
- Changing the derived changed_count in a preexisting plan is not rejected by
  the immutable plan digest alone. Its value must be derived again from the
  signed source/preimage hashes at the publisher's final admission step.

No claim of unauthorized live deployment follows from either probe: all
Original306/312/313 authorization conditions still apply. But code must not
be certified publishable until the stated invariants are implemented,
verified, and the correct first-party provider is enrolled via its approved
channel. Prior safety checks denied an attempt to edit these executor guards;
this audit does not circumvent that denial.

The audit gate also checks that the site-native policy and read-only Central
bridge have actually been installed. Currently they have not. An exit code
zero on a future local source audit would still NOT constitute release
authorization: a separately signed Master Admin/Original312/Original313
workflow remains mandatory.

## Original worker and signed-evidence compatibility (expanded audit)

These checks load currently installed first-party read-only functions
admission.checked_plan, worker_adapter.indexed_plans, registry.Registry, and
the Original313 evidence contract. They test an ephemeral root-private plan
directory only. They never invoke execute_claimed, create a Job, sign grants,
change installed registry files, or run Host execution.

1. The dynamic source candidate embeds source_binding inside the CAS plan.
   Original313 checks the exact plan digest including that extra field and
   responds ORIGINAL_PLAN_CONTENT_CHANGED. Removing the field makes the
   original core plan validate, but removes the operation binding.
2. The installed worker can index one plain CAS plan under two operations
   sharing the generic plan contract. This is an ambiguous mapping, NOT
   proof of unauthorized deployment: a distinct signed Job is still needed
   before dispatch. Future first-party admission must limit each plan to
   its specifically enrolled operation.
3. The source independent-verifier candidate always emits
   production_mutation=false; the installed Original313 evidence verifier
   requires the flag to equal environment == production. Current candidate
   cannot provide a compatible Production evidence payload.

### Required approved interface changes

- Preserve the core CAS plan bytes and SHA. A separately protected first-party
  association must pin operation ID, plan SHA, manifest hash, site UUID and
  target scope, and be validated before worker indexing and original312.
  Never use caller Job metadata or an unsigned fixture as authority.
- Do not allow another provider sharing plan_contract to claim the association.
  Deny ambiguous, missing, revoked or mismatched scope/binding.
- Ensure independent signed evidence has the correct environment flag and
  is bound to the genuine original Job and independently certified signer.
- Preserve the two existing blockers requiring explicit review:
  cross-source/target path equality and fresh changed_count recomputation.

The extended audit also differentiates an operation merely present in the
installed index from one whose handler, verifier, dependencies, manifest and
evidence schema SHAs pass the first-party Registry readback. Index presence
alone does not certify a release.

## Signed plan-association contract (source candidate)

Added operation_plan_association.py and the dedicated offline tests. The new
verifier accepts a first-party signed sidecar tied to one plan, one operation,
one manifest and the site's scope while preserving the original CAS plan bytes.
It returns verification facts only, not authority or a 313 receipt. The
installed Original306 worker does NOT import or invoke this verifier; the
release audit therefore adds ORIGINAL306_SIGNED_ASSOCIATION_HOOK_NOT_INSTALLED
as a hard HOLD reason. Full interface details and activation prerequisites are
in PLAN_OPERATION_ASSOCIATION_CONTRACT_20261112.md.

## Follow-up: Original313 environment flag fixed in candidate source

The verifier now checks all immutable plan/host receipt scope fields, exact
file list, grant nonce, successful apply and native Broker success marker
before deriving Production=true and Staging=false. No signed receipt is issued
and no site mutation was requested. The signed plan envelope incompatibility,
worker association enrollment and current Original312 admission remain HOLD.

The follow-up first-party audit runs synthetic Production/Staging receipt
binding fixtures and rejects mismatched company, environment, nonce, files and
unsuccessful apply receipts. It reports 10 native source-release blockers,
not a green deploy state. The separate global suite retains 9 failures and
14 errors (including the intentionally unresolved legacy provenance check),
so successful focused tests do not authorize release.
