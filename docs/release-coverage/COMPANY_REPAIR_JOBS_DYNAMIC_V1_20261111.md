# MAD4B — Dynamic Company Repair Jobs V1

Date: 2026-11-11. Central source: tours.dev.mad4b.com. Only Wayfatours Production was observed. All other companies remain excluded from testing and mutation.

## Source-backed dynamic provider registry

Repair operation names are not mapped to incident IDs in a static list. The central engine reuses MAD4B_Original_Native_Operation_Providers_V1 and checks the root-owned callback's repair_support contract for exact check coverage, provider and operation identity, the specific evidence contract, exact-site resource scope, signed Original 313 requirement and a distinct Original rollback job.

The existing general WP-CLI transport, unrelated Original 313 receipts and unsupported providers cannot authorize repair. Zero candidates produce HOLD. Multiple compatible providers produce an ambiguity HOLD, never arbitrary priority. An eligible new operation can be introduced by trusted source-backed registration without editing Repair Jobs' operation-name catalog.

The source policy retains 34 checks, 19 incident playbooks, 57 dependency edges and 11 diagnostic waves. It declares a dynamic-discovery contract, not hardcoded incident-to-operation mappings.

## Job lifecycle

A stable ID is derived from company, environment, exact Site UUID, HTTPS origin, incident, resource key and current source-plan SHA256. The central WP options journal is nonautoloaded, protected by a MySQL per-ID advisory lock, an event hash chain, an immutable job-metadata digest and readback. Duplicate creation reuses the existing ID and does not submit any new native operation.

A historic revision-zero journal may be upgraded to the immutable metadata digest only when its full stored descriptor matches the current plan. Started or conflicting journals fail closed. The state machine distinguishes missing baseline, source plan drift, provider not ready, an Original Job bound, pending result, unknown execution outcome, exact signed Original 313 proof, signed postimage drift and rollback admission.

Resume NEVER submits or replays native jobs; it reads already-existing bounded broker data. Prior jobs, different command digests, different site origins, environment, before/after hashes or rollback digests cannot close the repair. A signed postimage mismatch creates HOLD. Rollback planning always requires a new authorized Original job and independent 313 receipt. Repair Jobs do not mint Original receipts or change site content.

Current incident:<code> resource keys are logical incident identifiers, not specific WordPress resources. They deliberately cannot pass the native mutation preflight until a trusted exact-resource resolver and manifest are enrolled. A local baseline is unsigned and cannot replace an Original 313 receipt.

## Operations UI

The center's Company Repair Jobs page can inspect a single incident's dynamic Provider discovery or show the 19-incident read-only queue with one source assessment. This avoids repeated host WP-CLI reads per incident. Operator rows show priority, incident, evidence lane and current persisted status. The page never creates a job, grant or target write; those separate journal operations require exact central authorized WP-CLI.

REST reads are limited to administrators and use private/no-store responses. JavaScript was checked by Node and does not use innerHTML.

## Live Wayfatours acceptance

The Original Registry listed 9 registered operation providers, but none declared an eligible repair_support contract for the checked pilot. Pilot incident native_plugin_install_capability reports NO_TRUSTED_PROVIDER_DECLARES_REPAIR_SUPPORT and zero compatible providers.

One central repair journal was created for that incident, ID f672cfb110bbb5419a59738e8a05c0114aa98de9. A second create returned ALREADY_EXISTS. Safe descriptor reconciliation succeeded with unchanged revision 0. Resume returned WAITING_FOR_NEXT_GOVERNED_STAGE and never submitted Original 305.

The on-demand central queue returned 19 incidents, 1 persisted journal, 18 planned-but-not-materialized entries, 0 integrity holds. Wrong-environment lookup failed closed. The REST queue returned HTTP 200 for central administrator, 401 for anonymous, Cache-Control private/no-store. It created no jobs or company site writes.

## Tests

- 62 of 62 offline dynamic registry and journal assertions: unknown new operations, duplicate and ambiguous providers, forged capabilities, identity/plan separation, immutable descriptor tampering, stale baselines, no repeat Original jobs, unknown outcomes and rollback hold.
- 17 of 17 structural Original broker and exact receipt matching tests. These do not replace the real Original cryptographic verifier and are NOT presented as cryptographic signature tests.
- 35 of 35 source-policy dependency and evidence-lane assertions.
- 15 of 15 offline queue readback, corruption and wrong-company/permission assertions.
- 21 of 21 live central Start/Reconcile regression tests.
- Offline admin UI JS syntax and PHP lint passed.

## Open gates

1. Trusted exact-site resource resolver, company-owned pre/post digest and native resource manifest.
2. Per-check trusted provider repair_support declarations, and fresh Original 312 proof. No eligible provider was registered for the pilot.
3. An actual first exact Original 305 Job and independently signed Original 313 per-job readback for an approved repair.
4. Provider-specific signed rollback and before/after preservation acceptance.
5. Browser/language/AJAX/customer-facing acceptance for pages, filters, rates, checkout and brand content.
6. Original 318/313 signed release certification is HOLD.
7. Historical source-plan journal indexing is not implemented; the current queue covers the active policy fingerprint only.

## Change/rollback boundary

Live code changes are confined to central Company Manager includes and the central source policy. Main Company Manager plugin entry file, Wayfatours, all other company websites, Woo orders, Stripe and customer content were not modified. Preimage and later rollback backups exist under /opt/mad4b-company-provisioning/reports/company-repair-jobs-v1-20261111/. To roll back source code, restore Work Plan first and the policy/module preimages under the same report directory. The pilot journal is operational evidence and must be formally retired, not silently deleted.
