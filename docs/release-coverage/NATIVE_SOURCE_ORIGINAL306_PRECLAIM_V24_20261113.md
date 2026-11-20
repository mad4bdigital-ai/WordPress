# V24 — Original306 Native Source Signed Preclaim, source-only

2026-11-13. **Not enrolled, not published, not authorized for execution.**

The existing first-party Original306 `worker_adapter.py` already supports
manifest-based Preclaim and independently signed plan-to-operation association.
Rather than implement a parallel worker, this change declares a dedicated
`native-source-publisher-v1/original_306_preclaim.py` in the *source candidate*
manifest, with the existing original `executor-time-cas-v1/preclaim.py`
Central queued-Job read function as an exact SHA-pinned dependency.

Admission fails closed unless all these are verified independently:

- An immutable original 305 CAS plan SHA (never an unsigned `source_binding`
  field inside the plan) and a verified signed first-party association naming
  one specific enrolled operation/manifest
- Exact SHA-bound Original306 command digest for that operation
- Root-owned native policy, existing source-family Provider and exact
  before-change CAS, dynamic target identity and container binding
- A *fresh* queued original305 WordPress Job and a real operational dynamic
  Original313 finalizer readback from the existing first-party WP-CLI reader
- Exact signed Broker release Grant with matching original Job ID
- Independently signed Master Admin ticket, live approval state and fingerprint

This function creates NO Claim, key, grant, ticket, receipt, registry index,
source write or WordPress content. `new_job_issued`, `new_grant_issued` and
`original_313_certified` are always false. The source builder remains a
candidate-generator only. The new Registry preflight checks component bytes
under both source and installed layout, and still denies registration when
any original authority is missing.

## Remaining runtime incompatibilities

The existing `native_source_publisher.make_plan()` returns an *enriched draft*
with `source_binding` inserted into the original CAS dict. **That draft MUST
NOT be written to the original305 plan store.** The original Worker accepts
only a byte-for-byte immutable CAS plan, plus an *external* independently
signed operation association record. The existing host handler currently
expects the enriched draft on disk; it therefore needs a separately certified
signed-association rehydration before V24 can be deployed.

The finalizer is not yet a pinned executable Original313 signer; a read-only
independent evidence module is already present but is NOT a signed finalizer.
No `finalizer` capability is declared by this patch. Original306 Worker drift,
independent association trust enrollment, Central Write Ability, root policy,
first-party Registry upgrade approval and V19 guard publication all remain
active HOLD gates. The source candidate is not executable on the live system.
