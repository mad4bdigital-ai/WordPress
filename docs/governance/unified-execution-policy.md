# Unified Execution Policy — bounded live first (2026-11-19)

This change simplifies **how an operator learns the correct route** through the existing MAD4B authorization engine. It does **not** add a shadow authorization engine, bypass Control Plane authority, expand SQL permissions, or create wildcard grants.

## Operator experience

The original `MAD4B_SCP_Impact_Policy` remains authoritative for impact and approval needs. Content Experience routes are resolved **only when owned by an enabled installed profile**. New drafts and changes to existing drafts are ordinary. Publishing and modifications to already-public posts are impactful. Commercial (`price`, `rate`, `deposit`, `booking`, `tax`, etc.) metadata and user-permission changes must be confirmed. Package and filesystem mutations remain sensitive. Unknown target status is never a routine draft update.

`mad4b/execution-unified-plan` is an **authenticated, read-only** WordPress Ability mounted on `mad4b-read`; the same algorithm is available using `wp mad4b execution plan --ability=<registered-name> [--post-id=123]`. It displays three workflow labels (`routine`, `impactful`, `sensitive`), current governed-write mount status, existing authority readiness and actionable blockers. A routine workflow is eligible for no extra impact ticket *only if* the original classifier agrees and the original executor's exact actor/grant checks pass. An impactful workflow uses the existing one-time ticket. A sensitive workflow requires explicit review, backup and the original ticket. **The planner never acts on a plan or approves a ticket.**

## Host and source parity

The plugin and MU module were applied to Tours.dev Staging and Wayfatours Production, then compared by SHA-256 for exact byte equality. Both sites keep their own Content Experience route revisions and role-specific capabilities. The server catalog source includes the live `control-plane-test-route` and company media status/plan mounts that were missing in the older Git `master` snapshot; neither was discarded.

## Limitations and remaining work

This is the classification and unified discovery slice. Current write authority still returns false on both sites. Wayfatours Production still requires build provenance reconciliation, exact grants, candidate binding and enabling the controlled production mutation gate. `mad4b/production-write-grant-reconcile` needs a verified enrollment mount and canonical execution boundary. These protections are not relaxed by this change. Do **not** mark end-to-end writing as operational until an exact approved write, readback and rollback test succeeds.

Rollback: restore the two original Control Plane PHP files from `/opt/mad4b-backups/unified-execution-policy-20261119` on each VPS site and remove the newly installed `529-mad4b-unified-execution-plan.php` MU module. Preserve the rest of the site's live files, and verify WordPress read health. The originals and their SHA-256 sums were saved before the live change.
