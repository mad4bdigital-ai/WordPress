# Optional WP user for signed V4 staging identity renewal

Source: active Central Staging WordPress module
wp-content/plugins/mad4b-platform/modules/authority/299-mad4b-environment-signed-identity-v4.php
(mirrored in this source directory).

The existing REST owner workflow continues to require manage_options.
The independently enrolled VPS Native Principal can use:
wp mad4b environment-signed-v4-native refresh-all-due
or: refresh-due --company=CODE --environment=staging
without a WordPress --user override. This route only refreshes a P-256-signed
site identity using Central's existing pinned trust and authentic remote proof.

Native CLI guard:
- Central exact environment and canonical origin.
- Privileged root-owned CLI process inside the enrolled Central Docker container with no active WordPress actor. Web PHP www-data and anonymous ordinary WP-CLI are rejected.
- Original VPS host principal enrollment, host identity and ledger.
- Registered original VPS broker host.
- Signed remote P-256 response from the exact enrolled Staging company origin.
- No grant creation, job dispatch, identity reassignment, or tenant data write.

The existing Dispatcher telemetry step uses proof_renewal.py:
- No new timers or separate Broker.
- Enumerates enrolled Staging sites from the existing registry.
- Renews proofs near the 18-hour age threshold; signed V4 TTL is 24h.
- Preserves historical status contract for Channel Governance.
- Failures are reported and do not grant execution permissions.

Verified Staging (2026-11-05 UTC): ARE and ETG Signed V4 current; ETG
Company Portfolio Binding and approved operations_manage delegation restored by
independent proof refresh. Original 305 enrolled; Native Executor 312 not
certified for all operations and original 313 execution still must be proven.

Do not remove company-specific original 305 grants or owner-side REST CSRF
checks. Optional WordPress actor is not blanket job authorization.
