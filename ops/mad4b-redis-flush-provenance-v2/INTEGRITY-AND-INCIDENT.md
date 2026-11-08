# Read-only LiteSpeed hook integrity and Redis witness — 2026-11-08

The Redis caller monitor now verifies SHA-256 of the authorized LiteSpeed
`object.lib.php` wrapper and each company-specific MAD4B MU emitter every
minute. This is **read-only detection**; it never reinstalls or changes the
plugin after an upgrade. Unknown versions or missing/symlinked files cause
`PROVENANCE_HOOK_DRIFT_ALERT`, visible in the site admin status and host
journal.

A new root-cause investigation located a Redis SLOWLOG `FLUSHDB` entry
on Tours.dev at **2026-11-08 02:39:20 UTC**. The Redis client address matched
the enrolled Tours.dev PHP container on the active Docker network.
The site's Redis FLUSHDB counter rose from 99 to 102; three associated
PHP events were not captured. This **proves the Redis client was the
Tours.dev PHP container**, not the exact PHP script or WP-CLI command.
Its state remains `UNATTRIBUTED_FLUSH_ALERT`; do not resolve or suppress it.

The periodic SLOWLOG witness extension was *not* deployed: it failed its
authorization/readback process and the safe monitor was restored.
No Redis MONITOR, counter reset, flush, access-policy modification,
or automatic vendor reinstrumentation was performed.

Host tests: 23/23 PASS, including a missing emitter, an unknown vendor
SHA, a symlinked emitter, and both real site hook pins. The old Guard v1
and its release policy remain unchanged.

Live report: `/opt/mad4b-company-provisioning/reports/company-redis-flush-provenance-current.json`.
