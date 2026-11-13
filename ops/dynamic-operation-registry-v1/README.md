# MAD4B Dynamic Operation & Evidence Registry v1

This module is implemented and tested in the local Git worktree but it is not live-enrolled in the Broker or deployed to production.

The original 305/306/312/313 contracts remain authoritative. Existing read, plugin.install, schema.readback and mu-plugin.deploy operations are untouched.

## Implemented

1. registry.py: root-owned, SHA-pinned first-party registry of provider manifests, handler, verifier and evidence schema. No arbitrary shell commands or executable callbacks in manifests.
2. admission.py: immutable plan-to-Job digest matching, company/environment/site UUID/host scope, original claimed state, expiry and original 312 signed verifier. No hardcoded named environments.
3. worker_adapter.py: additive 306 adapter that discovers per-plan digests, preserves legacy operations, and reserves a Job before invoking a pinned handler. Source-only, not installed.
4. evidence.py: generic schema-based independently signed Ed25519 proof, with a verifier public key pinned in the registry. Never self-certifies original Central 313.
5. php/class-mad4b-dynamic-313-verifier.php: standalone Central-compatible verifier for the same canonical Python-signed envelope. It is not a WordPress ingestion endpoint.
6. company.code.release source manifest: pins the existing Code Release host handler and independent readback module with command and schema digests.

The file index.preview-NOT-ENROLLED.json intentionally records installed_by_first_party=false. No verifier key is installed in its place.

## One-time governed integration still required

The first-party Broker must install a protected registry at
/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled
and a protected plan store at
/var/lib/mad4b/dynamic-operation-registry/plans.

One original 306 Worker release must merge the legacy digest table with
worker_adapter.digest_table and route only real original claimed Jobs through
worker_adapter.execute_claimed. Per-operation static routing is no longer
necessary after that integration.

Central original 313 needs a first-party WordPress ingestion and finish path
for the generic signed evidence, using the separately installed verifier key,
enrolled Provider and original 306/312 Job. The current Central finalizer still
uses its old static 313 verifier; this PHP candidate does not alter it.

Each execution remains bound to an original signed Job, 312 claimed authority,
plan-specific Broker grant, verified WordPress runtime, independent readback and
independently issued original Central 313 receipt. No module here mints them.

The source Host handler checks an actually installed first-party registry (if
present) and immutable enrolled plan before the old static Worker fallback.
It rejects unregistered or stale plans.

## Tests

python3 -m unittest discover -s ops/dynamic-operation-registry-v1/tests -q
python3 -m unittest discover -s ops/company-code-release-v1/tests -q

A Python-signed example proof was accepted by the isolated PHP verifier
and a tampered proof was rejected. Synthetic test keys/authority are not
production evidence.

Status: SOURCE_IMPLEMENTED_NOT_ORIGINALLY_ENROLLED_NO_PRODUCTION_MUTATION.

## First-party v2 Central native_finish bridge (2026-11-08)

`code_release.v2` carries the independently signed native Host receipt digest,
Broker grant/transport IDs, output and postcondition hashes, and mutation
classification. The existing original Central `native_finish()` remains the
only issuer of the final 313 WordPress receipt. The new 313b fallback calls the
verified, persist-once dynamic proof ONLY when a legacy proof is absent, and
ONLY after the original signed Broker finish passed the original checks.

`upgrade_first_party_registry_v2.py` and `upgrade_central_313_finalizer.py`
perform protected first-party upgrades with private backups. The independent
proof and Broker finish must use separate signing domains. A PHP integration
harness successfully exercised valid signatures, proof replay denial, invalid
Broker signature rejection and original 313 finalization without changing
real WordPress options.

The original 306 Worker additionally requires `operational_ready=true`,
which the new WordPress integration deliberately reports as `false` until the
original host receipt writer and independent readback/signing pipeline are
fully installed and verified. No source-only change, signer key or manifest
is itself authority to issue an original 306 Job or mutate Production.

### Authoritative Central 313 v4 reconciliation

A newer first-party Central 313 v4 bridge already exists in the live MAD4B
Platform. Keep its separate `mad4b.native-313-independent-host-proof.v4`
signature and persisted Dynamic evidence. Do NOT deploy the older fallback
adapter when v4 is present. The first-party operation must use the enrolled
Registry's active Evidence Schema ID rather than a hardcoded `code_release.v1`.

`php/313b-first-party-dynamic-v4.php` is a source snapshot of the actual v4
bridge plus a narrowly scoped schema selection fix; `php/class-mad4b-dynamic-313-*`
carry matching proof/readback binding. `integrations/reconcile_central_313_v4_schema_v2.py`
pins prior live hashes, saves root-only rollback copies, deploys the v4-compatible
sources, and publishes a version-2 trusted provider config last. The existing
Broker `native_finish` is NOT modified.

The live Worker still requires `operational_ready=true` in addition to a
connected original finalizer, so connecting v4 does NOT issue a Job or permit
any target release until the original Host receipt and signed 312 lifecycle is
complete. The old `upgrade_central_313_finalizer.py` refuses to patch a live
v4 bridge and is retained only for historical source review.

### Original 306 exact signed release Grant preclaim

The first-party original Worker now requires a root-only plan copy, unchanged
preimage and live runtime, and an exact separately signed, non-expired Broker
Code Release Grant with matching original `job_id` **before** claiming or
reserving a dynamic Job. See `integrations/patch_current_worker_preclaim.py`.
A missing grant, stale target, wrong provider, imported Python from an
unregistered location, or reused reservation stops the Job without claiming
it. Neither the operation Registry nor status of Central 313 can issue a Grant
or stand in for the authenticated owner request.

### No manual Central 313 operational flag (2026-11-08)

The original Central 313 v4 check now derives `operational_ready` from its
actual loaded provider v3 / evidence-schema v2, independent native v4 bridge,
Broker 306/312 classes and the live original Broker public signing key. The
actual original 306 Worker, still a separate first-party trust boundary,
checks the protected Host independent verifier key against the Registry,
original signer and Resumer files, active original Worker/Resumer timers,
root-only plan/grant/receipt stores and protected Host release policy.

This readiness is **NOT an authorization grant**. Every dynamic Job still
requires a separately admitted original 305 request, signed 312 authority,
original exact-scope root-only Plan, live target preimage, and Job-bound signed
Broker release Grant before 306 Claim/Apply, followed by genuine signed 313.
No fixed environment-name exemptions; changes are matched by enrolled target.
The v5 Private Draft provider and all previously registered 306 operations
remain intact. The first-party source guard pins the latest active worker and
refuses to overwrite unknown newer code.

### Original 306 Claim precedes native Host pending 313 (live regression)

An original Code Release Job can be signed and claimed by the real Broker
before Host execution. Therefore WordPress has an original Broker `CLAIM`
record, but **not yet** the `PENDING_INDEPENDENT_NATIVE_READBACK` record;
that record is generated only after Host Apply. The dynamic selector reads
actual original `JOB` and `CLAIM` fields, requires exact Job ID/host/nonce,
matching claim timestamps, unexecuted status, original command SHA and scope,
then rechecks original independently signed 312 before reserving or executing.
It never manufactures or pre-fills any 313 pending receipt. Later original
313 verifies the genuinely persisted Host readback and creates the final
central receipt. Tests cover forged/missing claims, clock drift, premature
313 pending records and exact signing continuity.
