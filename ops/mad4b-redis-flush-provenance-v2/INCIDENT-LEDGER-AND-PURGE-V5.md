# Incident ledger and direct LiteSpeed purge intent — v5

As deployed on Tours.dev Staging and Wayfatours Production on 2026-11-08.

## Historical incident retained

Redis FLUSHDB counters: Tours.dev **102** and Wayfatours **46**.
The source's earlier increase from 99 to 102 remains **three confirmed
unattributed counter increments**; one SLOWLOG witness at 02:39:20 UTC
was sourced from the enrolled Tours.dev PHP container. The actual
executing script was not proven.

Previous observer behavior could consume later, unrelated PHP callback
events to retroactively clear an old missing-source incident. The v5
incident ledger migrates older pending counters to
`confirmed_unattributed_count` and **does not erase that evidence** due
to later events or Redis restarts. For NEW counter increments it allows
one polling window for delayed PHP callbacks, then preserves unresolved
ones as a separate durable alert. A new, independently attributed
flush is recorded in `latest_activity`, without suppressing historical
alarms.

## Additional direct LiteSpeed pathway

Code review found `Purge::_purge_all_object()` calls
`Object_Cache::flush()` directly without passing through the installed
`wp_cache_flush()` provenance hook. LiteSpeed emits WordPress event
`litespeed_purged_all_object` **before** that backend call.

The MAD4B MU emitter now subscribes to that event and records
`event_kind=purge_intent` with `successful=false`. This is evidence
of a purge request **not confirmation of FLUSHDB**. The host observer
archives and classifies intents separately; it never uses them as proof
of a completed Redis command. No new vendor-file modifications were
required for this coverage. Another direct path, the Object Cache
`_reconnect()` flow on a changed drop-in file, remains monitor-only:
no safe WordPress pre-flush callback exists for it in the inspected code.

The installed WordPress drop-in currently matches the LiteSpeed
canonical drop-in. There is no proof either direct path caused
the historical Tours.dev incident.

## Tests and controls

**35/35 Python regressions passed** on VPS, plus PHP syntax on both
containers and synthetic PURGE intent tests without Redis writes.
Both site MU plugins show the purge hook registered. Read-only
instrumentation SHA readbacks PASS for both sites.

The site-level protected log records only valid sanitized names and
no customer requests, Redis keys or CLI arguments. The service
preserves its strict admission/CPU controls and never calls FLUSHDB.

The previously denied automatic LiteSpeed reinstrumentation remains
**not deployed**. Unknown future plugin upgrades alert on hash drift
and need approved review; this release does not work around that guard.

VPS: `/opt/mad4b-company-provisioning/reports/redis-flush-provenance-v2-20261108/`
