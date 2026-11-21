# MAD4B runtime owner recovery, 17 Nov 2026

## Why this is needed

The PR814 source-integration branch previously contained an inert Commerce bootstrap candidate and a handful of Platform/JetEngine files. Installing that partial tree as a real WordPress plugin was unsafe: 18 direct Commerce require_once files were absent, as were the original Platform and JetEngine plugin owners.

## Recovery and selection

Recovered three original plugin baselines from the enrolled Tours.dev WordPress runtime without modifying the site, into a detached local Git worktree:

| Owner | WordPress plugin version observed | Files copied |
| --- | --- | --- |
| MAD4B Commerce | 0.1.2 | 315 |
| MAD4B Platform | 0.2.2 | 496 |
| MAD4B JetEngine | 1.0.0 | 94 |

The result is a 905-file versioned source owner snapshot. SHA256 digests for every file, rejected runtime backup names, origin and preserved overrides are recorded in live-runtime-source-manifest.json.

Never overwrite existing central versions without review. The existing newer Commerce modules/compat/tour-content-layout-control.php was explicitly preserved; Platform authority/dynamic313/receipt-only-finalizer was central-only and preserved; the Company Home Sections, Pricing Workspace and structured-price tag already matched the live source.

Commerce 0.1.2 bootstrap contains native Fluent Forms/WooCommerce pickup fields and current logistics code. Kept that intact and inserted only the previously tested conditional read-only Dynamic Price Tag fallback from the feature source, guarded against duplicate registration and activation of payment/write adapters. Did not revert to source Git's stale 0.1.1 bootstrap or the seven other stale live-drifted Commerce files.

Excluded 112 files that were backups, inactive drift stages, temporary locks or a symlink outside plugin ownership (legacy-mu-tree/currency-data points to separate MU namespace); their paths are recorded. No private key or live payment token signature was admitted to the restored tree. Site-owned MU currency-data is a separate dependency, not silently duplicated under the plugin.

## Contract checks

- Commerce direct/transitive PHP dependencies: 23/23 files present (18 originally missing direct + five transitive). Native optional Pickup and Structured Price Tag modules exist.
- Original ordered Platform MU map: 378/378 mapped modules present; no duplicate paths.
- JetEngine module discovery: two module entrypoints present (functional-modes and hub).
- PHP 8.5.12 lint: 655/655 PHP files, zero parse errors, in isolated /tmp on enrolled PHP container.
- Source SHA256 integrity: 905 selected code/asset files.
- Price/Home/Experience contract suites are tested again after staging.

## Deployment boundary

This closes the missing source-owner file-dependency blocker in Git only. It does not enroll or certify a new Original313 Code Release. Each plugin owner requires exact target preimages, an independently signed 312/313 job, actual Host postimage and independent acceptance. Historical Original313 success is not a new release grant. Source Platform is central-only and must not be deployed to Wayfatours merely because it is in the source Git tree.

No active WordPress files or options, production payment settings, rate amounts, bookings, JetEngine definitions, or tour publish states were modified. No GitHub master push or fleet deployment was done.

The previous experience-unity/central-source-integration-20261117.json is an immutable historical receipt of the blocked state. Use the new live-runtime-source-manifest.json and runtime-owner-closure test suite for the repaired Git source status.
