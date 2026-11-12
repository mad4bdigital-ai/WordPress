# MAD4B Company Reconcile — Original 306/313 Dynamic CAS Extension V2

Date: 2026-11-12. Scope: Central tours.dev.mad4b.com and Wayfatours Production only.

## Published in Central (read-only operation routing)

- The first-party per-operation Original 313 proof router and native verifier extension are deployed on Central. They validate an explicitly enrolled operation and matching independent evidence rather than assuming every new operation is company.code.release.
- The existing root-owned Central Original 313 config remains unchanged. Company code release retained legacy operational_ready=true and evidence schema code_release.v2 in an actual WordPress CLI readback after deployment. CAS operation_enrolled=false, CAS finalizer operational=false, and no Original 313 receipt issued.
- Wayfatours business settings, pricing and metadata were not changed. The previously published target-side CAS code remains present, read-only validated.
- The live Original 306 Worker, Root Registry index and Original 313 code-release Finalizer remain unchanged.

## New source components (not yet Root production enrolled)

### Dynamic Original 306 Preclaim

- Source path: ops/dynamic-operation-registry-v1/executor-time-cas-v1/preclaim.py
- Verifies exact first-party Registry operation, content-addressed source plan and command SHA, a fresh Original 305 queued Job with exact company/site/origin/environment and original grant/transport/host, and a root-protected signed operation-specific Release grant.
- Requires Central operation_operational_ready for that exact provider. Generic Original 313 readiness, a stale signed capability or an unsigned imported baseline never suffices.
- No Grant, Claim, Job, owner certificate or alternative Original authority is created.
- The first-party worker_adapter.py discovers preclaim from the SHA-pinned manifest, without adding static named branches for CAS. A source-only candidate Original 306 worker diff inserts exactly eight lines; code-release and plugin-license paths are unchanged. The live Root Worker has NOT been patched.

### Independent Original 313 CAS Finalizer

- Source path: ops/dynamic-operation-registry-v1/executor-time-cas-v1/finalizer.py
- Re-reads the exact target WordPress postimage and one-use replay fence independently of the mutating Worker. Checks immutable root Host receipt, original Claim, Broker-signed Release grant, job/plan/command digests, exact source-owned target and signed mutation time.
- Produces a dynamic independent postcondition proof and Original native v4 proof, signed by the existing separate Original 313 verifier. Reuses the existing Central dynamic-313-proof, native-313-proof and broker-remote-ssh wpcli-finish verification and persistence. There is no alternate certifier.
- Receipt recovery does NOT repeat CAS Apply; a changed or expired proof remains HOLD.
- Evidence schema resource_cas.v1 now includes grant_id, transport_id, output_sha256, expected_readback_sha256, readback_sha256, native_receipt_sha256, postimage and company-preservation fields. The pre-signing readback state is explicitly unsigned.

### Per-operation central trust

- Central PHP verifier now selects a provider by its pinned operation_id, rejects undeployed operations, and validates an explicit source-owned finalizer_operations list. Its legacy default remains company.code.release, so the existing config is not forced into a multi-provider migration.
- Native 313 checks the stored independently signed proof for the exact observed operation and source-owned evidence schema, not a static operation equality.
- No CAS provider or finalizer operation was added to Central production config.

### Version-neutral Registry upgrade

- Read-only planner at ops/dynamic-operation-registry-v1/executor-time-cas-v1/integrations/plan_original_registry_upgrade.py validates source hashes and proposes adding the CAS provider to the existing three active operations (three to four) without replacing the original catalogue.
- No first-party Root install, existing provider overwrite, private signing key read, original claim, or production mutation is performed by the planner. The obsolete one-time first-party installer is not used for upgrades.

## Formal release gates remaining

1. Publish content-pinned provider handler, Preclaim, Finalizer, independent reader, updated Registry and Worker adapter via approved Original 318.
2. Publish and verify the operation-specific Central Finalizer trust and source-owned routing configuration, then activate the original 306 Preclaim entry through the governed first-party Worker release.
3. Issue a genuine owner-approved Original 305 Job and exact signed operation Release grant for a non-commercial rate_title test; no numeric price, children/infants, inventory, checkout or payment mutation permitted.
4. Require a fresh WordPress postimage, host receipt and independently signed Original 313 certificate before marking a single repair job certified.
5. Integrate the source-plan expected after-image, restorable rollback and CAS evidence with central Resource Repair Journals before extending beyond a single controlled resource.
6. Other companies remain outside this pilot until Reconcile/Full Clone.

**Status:** Read-only Central Original 313 routing LIVE and preflight proven. Mutating CAS Provider and Original 306 Worker Hook remain NOT enrolled, and signed Original 318/313 composite certification remains HOLD.
