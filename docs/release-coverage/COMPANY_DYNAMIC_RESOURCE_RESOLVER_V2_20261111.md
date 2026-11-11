# MAD4B — Dynamic Resource Resolver V2
2026-11-11. Central source: tours.dev.mad4b.com. Only Wayfatours Production was observed. No tenant site was modified.

## Design

Dynamic Repair providers now declare a resource_resolver contract, exact supported checks and resource types, and a read-only public static callback located in the SAME root-owned source file as the Original Registry readback callback. The central runtime never selects operations by name, never accepts a caller-provided URL, command or callback, and never treats a generic WP-CLI transport as a repair capability.

Resolver request binds company, environment, HTTPS canonical origin, Site UUID, source-plan SHA, resource type and resource ID. A provider observation must be recent, exact-site scoped, authenticated and expressly read-only; ownership, before image, resource manifest and company-preservation SHA digests must be present. A fake Original 312/313 or a mutation claim is rejected. Source ownership rules override provider reclassification.

An unsigned semantic resource-resolution SHA excludes observation timestamp, so the same resource with unchanged bytes is idempotent even if re-read later. Freshness is independently bounded to 600 seconds; before baseline and Original preflight the exact resource is re-read to prevent applying an old plan after the tenant changes.

Incident journals remain logical. An exact resource produces a separate immutable child journal keyed to the concrete resource, company, environment, UUID, origin, incident and policy SHA. Resource child metadata and original source observation are immutable, and must be compatible with a fresh target baseline. Child indexes are protected by parent/child MySQL advisory locks, with SHA256 accidental-corruption detection. The read-only central queue displays child counts and integrity holds. V2 currently bounds each child index to 64 entries; pagination is a future improvement.

Create-resource is an explicitly scoped central administrative WP-CLI action and only persists a journal after an eligible source-backed resource observation. It NEVER submits an Original 305 job or executes a tenant mutation. Actual execution and rollback still require Original 312 and exact independent 313 receipts. The administrator-facing resource-preview URL is read-only and private/no-store.

## Tests and acceptance

- 51/51 exact-site resolver and negative-case assertions.
- 28/28 complete mock WordPress resource lifecycle assertions, including duplicate prevention, two distinct resource child jobs, owner reclassification HOLD and tampered index HOLD.
- 67/67 dynamic provider/journal assertions.
- 17/17 structural Original job/readback matching assertions, NOT cryptographic signature integration.
- 15/15 existing queue compatibility tests.
- 35/35 source policy/dependency tests.
- 21/21 real central WordPress regression tests.
- Node JavaScript syntax verified for resource-preview UI, PHP lints passed.

## Actual Wayfatours result

Pilot native_plugin_install_capability remains NO_TRUSTED_PROVIDER_DECLARES_REPAIR_SUPPORT. Resource-preview returns RESOURCE_RESOLUTION_HOLD / NO_UNAMBIGUOUS_TRUSTED_PROVIDER_RESOLVER. This is correct because no provider has yet enrolled a trusted, resource-specific readback. Wayfatours Production retains 19 incident scenarios, one old revision-zero logical journal and ZERO exact-resource child journals. There is no new job, native 305, grant or payment action.

Central endpoint administrator HTTP 200, anonymous HTTP 401, Cache-Control private/no-store. No source policy or Company Manager main entry file was modified. The four changed central code files match live source bytes, with root ownership 0644. All other companies and Wayfatours remain untouched.

## Open gates and rollback

A real provider must declare exact-resource resolution and fetch authenticated WordPress target bytes. An authorized Original 305 job and independently signed Original 313 remain necessary before any claim of successful repair, and a separately signed rollback is still required. Original 318/313 composite release certification is HOLD. Tenant-preservation and browser/language/rate/checkout acceptance are separate.

Central code preimages are archived under /opt/mad4b-company-provisioning/reports/company-resource-resolver-v2-20261111/preimage. Restore the prior Repair Jobs, Capability Discovery and Repair Job Contract before removing the new Resource Resolver class. There is no Wayfatours data rollback.
