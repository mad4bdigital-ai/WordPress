# Native VPS Operator dynamic SQL target integration

This is the reconciled version of the original root-only
`vps-native-operator.py` from the authorized VPS runtime. The source
code retains the **same SSH/systemd identity, root-owned policy,
company/environment scope, exact command hash, rollback, receipt and
readback requirements**. It changes SQL runtime lookup only.

For SQL operations, the original root-owned Company Target Registry
provides company, environment, Site UUID and canonical origin.
`native_operator_target.py` then resolves the current Coolify PHP
container from its labels and verifies the active WordPress Site Profile
and siteurl. Database discovery requires the same Coolify service UUID,
environment, healthy DB role and a unique match. Plans pin full Docker
Container IDs for the current PHP and DB. The operator compares these IDs
again before executing, and calls Docker using the immutable DB ID.
There is no fallback to a stale container name or another company.

The host installation consists of three root-owned Python files in the
existing Native Operator directory: `vps-native-operator.py`,
`native_operator_target.py`, and `dynamic_runtime.py`.
The original operator has a root-only recovery copy under
`backups/faore-dynamic-runtime-20261105/`. Rollback replaces only the
operator file from that copy; the helper modules are inert if unused.

Verified with the **real host**: Native Operator `status` PASS;
read-only SQL `plan` for Wayfatours Production PASS; a separate
`SELECT 1` operation returned VERIFIED with independent readback,
`mutation_performed=false`. This operation was **not** a Broker 313
receipt or a Write authorization for FAORE. No production content was
changed. A changing container ID is a denial, not permission to replay.

Older one-off repair/release scripts with embedded legacy
`target_container` remain separate consumers and are NOT migrated by
this Native Operator patch. Do not update a signed historical payload by
replacing container names; create a new scoped operation if needed.

## Original independent 313 runtime discovery
The additional source file coolify-php-runtime.py replaces the original
constant-bound Coolify PHP discovery script. It retains the exact
discover(origin, site_uuid) interface used by both original-wpcli-executor.py
and independent-readback.py. It obtains the company/environment from the
root-owned original target registry, resolves a unique Coolify resource,
requires current WordPress site and origin binding, verifies the live
/var/www/html bind mount and returns immutable Docker Container ID.
Signed 305/306/312/313 contracts, job IDs, grants and readback routines
are untouched.

The original script is backed up on the VPS under
native-313-independent/backups/faore-dynamic-20261105. Direct host
readback and original-wpcli-executor.pinned_target() passed after installation.
No new 313 Job was created or claimed during testing. Future 313
success still depends on the original claimed Job and independently
verified receipt, not runtime discovery alone.
