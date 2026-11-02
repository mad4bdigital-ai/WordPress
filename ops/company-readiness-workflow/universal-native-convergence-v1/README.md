# Universal Remote Control — Original 305 / Host Proof convergence

This code mirrors the root-protected Central Staging native-contract reconciler and removes a Wayfa-only path. It **reads** Original Core Signed Host Proof for all discovered company/environment entries with registered identities and pending original 305 pairs. A current and exactly matched proof changes the **recommended original typed transition** to `READY_FOR_ORIGINAL_306_312_SIGNED_READ_CANARY`, not the transport, grant, job, or WordPress state itself. **Original 312 deliberately expects the original 305 transport state `PENDING_INDEPENDENT_SSH_VERIFICATION`.** This is not a missing transport-activation step; rewriting it to ACTIVE would invalidate the original 305/312 admission contract.

## Key state separation

- An authenticated host SSH probe is infrastructure connectivity, not a company/site-specific Production authorization.
- `ORIGINAL_305_PERSISTENT_PAIR_ENROLLED` does not imply `original_active_transport_present`.
- Signed Host Proof is bound to the original company/environment, fresh (under 15 minutes), verified by Original WordPress, and not claimed to certify the Native Executor.
- `original_transport_activation_performed=false` and `production_mutated_by_reconciler=false` are always emitted by this **read-only** convergence.
- No default Production grant, no fabricated Site UUID, no cross-company wildcard, no WordPress actor substitution. Previously enrolled original actors and per-site original issuers must approve actual transitions.
- Actual 306 owner-authenticated Job, original 312 claim/verification, external native target execution, independent 313 receipt, and rollback/canary remain separate.

## Live state observed on 2026-11-02

5 companies / 7 environments, 3 current Signed Host Proofs: All Royal Egypt Staging, Egypt Tour Gates Staging, and Wayfa Tours Production. All 3 have an original 305 transport in its intentionally PENDING pre-job state, ready for an owner-issued signed per-command Job; none has independently certified `313`. Three Production environments lack original exact Site UUID and cannot be activated yet. Tours Central is a controller, not a managed external company.

Production remote control is **requested** and is supported by the same discovery logic, but actual remote control is **not enabled** until the original per-site Site Profile, owner permission, signed Host Scope, trust and pre-job 305 transport are enrolled and a genuinely signed 306/312/313 end-to-end canary is certified. Only then can the certified Native Executor be used. No Production WordPress mutations were performed.

Run isolated contracts: `python3 -m unittest -v test_universal_native_reconcile.py`.
VPS live mirror is `/opt/mad4b-company-provisioning/bin/company-native-contract-auto-reconcile.py`; the scheduled service uses its own registered `systemd` lock. This PR source is **not** an independent authorized issuer or a Production deployment.
