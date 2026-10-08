# Feature 007: offline CI and independent release acceptance

Status: CI-OUTAGE SOURCE PREFLIGHT ONLY. No release, Production, grant, OAuth, host or Staging authorization is created by this file.

## Native offline alternative

On a clean exact checkout of PR #258, use the source-only runner under the Control Plane tests directory. On Windows PowerShell:

    $Head = (git rev-parse HEAD).Trim()
    $Base = "c357bc995b2c831bd9d5a7d0df596d2d39bd3dd2"
    py -3 wp-content/plugins/mad4b-site-control-plane/tests/feature007-manual-preflight.py --expected-head $Head --base-sha $Base --php74 "C:\php74\php.exe" --php83 "C:\php83\php.exe" --report "$env:TEMP\feature007-$Head-local.json"

On Linux, with real independent PHP 7.4 and 8.3 installed:

    SHA=$(git rev-parse HEAD)
    python3 wp-content/plugins/mad4b-site-control-plane/tests/feature007-manual-preflight.py --expected-head "$SHA" --base-sha c357bc995b2c831bd9d5a7d0df596d2d39bd3dd2 --php74 php7.4 --php83 php8.3 --report "/tmp/feature007-$SHA-local.json"

Return codes: 0 = selected **local source checks passed, external acceptance still pending**; 1 = FAIL; 2 = BLOCKED (missing PHP engine). The JSON result always declares GitHub CI, Staging, host isolation, Production and external provider acceptance FALSE. It is unsigned, does not replace an Actions status, and never grants execution permission.

This offline runner checks exact checkout, baseline ancestry, clean worktree, exhaustive path ownership/digest, exact G6/G8 workflow hash and preserved GA/GB/OAuth jobs; then lints runtime files, runs representative hermetic PHP fixtures on both exact minor versions and Python source contracts. It requires no network/WordPress access, but DOES NOT independently enforce an OS network namespace. Reports must be stored outside Git checkout. Never feed it live credentials.

## Optional real disposable SQL matrix without GitHub Actions

Only on a disposable operator workstation with native PHP 7.4 and PHP 8.3
plus mysqli, a locally running Docker daemon, and trusted **preloaded**
mariadb:11.4 and mysql:8.4 images. This matrix refuses ambient G8_CAS_,
MYSQL_, or MARIADB_ variables, requires an explicit opt-in flag, refuses
a dirty/non-exact git checkout, generates unique temporary root passwords,
binds the published DB port to 127.0.0.1 only, asserts invalid-name
safe-refusal exit code 2 and actual CAS success, and removes containers
on exit. It does not pull missing images automatically.

From PowerShell, adjust the PHP paths:

    $Head = (git rev-parse HEAD).Trim()
    py -3 wp-content/plugins/mad4b-site-control-plane/tests/feature007-disposable-db-matrix.py --expected-head $Head --php74 "C:\php74\php.exe" --php83 "C:\php83\php.exe" --allow-disposable-docker --report "$env:TEMP\feature007-$Head-disposable-db.json"

The same SQL fixture now exercises the real `MAD4B_SCP_Operation_Journal::begin/append`
source using separate disposable InnoDB head/event tables, including an
injected event-insert failure, head-CAS failure, rollback observation,
duplicate-genesis rejection, stale CAS refusal and hash-chain readback.
The matrix rejects a PHP exit=0 result unless the SQL harness emits exactly
one `G8_JOURNAL_TRANSACTION: PASS` and exactly one
`G8_MYSQL_MARIADB_CAS: PASS`, in that order, after disposable fixture cleanup.
Missing, repeated or out-of-order markers fail closed, even if the PHP process
exits zero. These literal markers are receipts, not independently signed proofs. A test-only transaction guard is
used to connect the journal source to real SQL; this is **not** certification
of the complete WordPress transaction guard, provider execution, Staging,
or faulted network commits. A genuine native PHP/Docker test run is still
required before the local SQL acceptance result can be claimed.

The result is **LOCAL_DISPOSABLE_DB_MATRIX_PASS_ONLY** if all four
database/PHP combinations pass. Missing Docker/PHP/image is BLOCKED,
not PASS. Any failed CAS/refusal/cleanup is FAIL and the receipt lists
any containers that could not be removed. The report never embeds a
password. The local images still need their own independent provenance
review, and a local DB result is not a real Staging migration, restored
state, site-bound workflow, production, or GitHub CI certificate.

## Independent gates that remain mandatory

| Scope | Required acceptance evidence | Can repository fixtures replace it? |
|---|---|---|
| GitHub branch CI | Exact-head status from the required workflow/ruleset checks | No |
| G1/G2 | Enrolled provider reads, OAuth PKCE/refresh/consent and operator/browser audit | No |
| G3/G4 | Signed native provider packs, readback, WooCommerce/WPML/editor/builder canaries | No |
| G5/G6 | Actual model/SEO account consent and budgets, vector/PII isolation, crypto rotation | No |
| G7 | Native journal, compensation, independent non-root host network/resource canary | No |
| G8 | Native MySQL/MariaDB CAS, clone/restore, workload and queue fault metrics | No |
| G9 | Native flock exclusion, multicustomer isolation, signed post-restore claims | No |
| GA-GF | Durable CAS tasks, authoritative desired state, provider solver, governed provisioning, operator UI | No |
| Performance | At least 3 comparable independent frontend samples for response time, DB queries, and peak memory | No |
| Packaging | Reproducible exact-source ZIP, manifest, installation and rollback proof | No |
| Production | Distinct Production Site Profile, OAuth opt-in, database and runtime gates, exact one-time approval | Never |

No pending item above can be converted to PASS merely because GitHub Actions remains queued.

## Staging Write convergence — separate authority action

A live read-only diagnostic on 2026-10-08 reported: staging, adapter lifecycle ready, current grant snapshot ready, database topology ready, read-your-writes ready, but write_authority_ready=false, candidate_binding_match=false, and runtime_authority_candidate_not_reconciled. A successful source merge cannot repair these runtime states. Re-read current deployed artifact, profile revision, restore epoch, runtime generation, subject, grants and candidate fingerprint. Request the existing **Write-only governed convergence plan**, verify its exact SHA/expiry/revisions and scope, then request separate explicit one-time approval. Only thereafter may the authorized operation apply and obtain same-cycle independent readback. Never treat OAuth step-up as a write grant, and never apply a stale plan.

## Developer host ownership

The platform operator must establish an actual verified non-root worker UID, effective prlimit limits, and bwrap/unshare network namespace isolation. The presence of binary paths or an available PHP function is not a sandbox execution certificate. An independent disposable host canary must prove blocked egress, CPU/memory/process/file limits and bounded cleanup. Bind any accepted receipt to the exact site, Origin, source artifact, generation and restore epoch. Until a separately verifiable current receipt exists, execution state remains NOT_CERTIFIED. This source work deliberately does not install OS packages, run shell inside WordPress, enable raw-SQL Breakglass or widen Production grants.

## Final decision

Keep PR #258 Draft and fail closed. Do not merge to master or deploy to Production until native repository, external providers, staging browser/host/database, reproducible packaging, owner/branch rules, and one-time release authorization all match the same exact source. Record any unavailable check as BLOCKED, not PASS.


## All Royal Egypt — WordPress host environment correction

**Observed 2026-10-09, read-only live connector:** WordPress `wp_get_environment_type()` reports `production` because its environment is **not explicitly configured**; the exact MAD4B Site Profile is `staging`, with correct Staging origin and bound identity. This is a host-bootstrap configuration mismatch, not permission to retarget the Site Profile.

**Authorized Staging host operator action only** (never in a WordPress admin option or browser-provider credential):

1. Confirm this is the **Staging** installation and back up the current `wp-config.php` securely outside the repository. Inspect any host-level `WP_ENVIRONMENT_TYPE` env var, MU bootstrap and constant for explicit contradictory settings; do not override an intentionally configured Production environment.
2. In Staging's `wp-config.php`, **before** `require_once ABSPATH . 'wp-settings.php';` and before any WordPress bootstrap that reads environment type, set:

   ```php
   // ONLY on the separately verified All Royal Egypt Staging host.
   define( 'WP_ENVIRONMENT_TYPE', 'staging' );
   ```

   Use exactly one authoritative definition (no duplicate `define`, no secrets in Git). Deploy through the host's controlled config channel; do **not** modify Production's config or depend on a plugin loading after WordPress boot. If a different explicit environment definition exists, reconcile it at the host instead of silently overriding it.
3. Independently read back `wp_get_environment_type()` / `mad4b/site-profile-status` from the actual Staging origin and inspect `wordpress_environment=staging`, `wordpress_environment_explicit=true`, `wordpress_profile_mismatch=false`, `environment=staging`, `origin_match=true`, and unchanged exact Site Profile UUID/revision/digest. Re-read build provenance and Staging gates to ensure no collateral drift.
4. If any check is absent, stale, cross-origin or fails, mark **HOST_ENVIRONMENT_NOT_CERTIFIED**, roll back only the host config adjustment via the site's approved change procedure, and do not claim final Staging or Production readiness.

**Boundaries:** This document cannot update the actual site's host configuration. The plugin's implicit default override is an existing compatibility behavior, not proof that WordPress has been configured as Staging. Never change `Site Profile.environment` to `production` merely to eliminate this diagnostic.

## Exact-head browser source test and evidence levels

The isolated test fixture `tools/browser-acceptance/test-site-provider-configuration.mjs` targets the pure ETG operator/driver contract. Run it on a clean **exact-HEAD** repository checkout using `node tools/browser-acceptance/test-site-provider-configuration.mjs`; run the WordPress source fixtures with PHP 7.4 and PHP 8.3 independently, including `tests/browser-acceptance-admin-setup-contract.php` and `tests/staging-browser-site-selection-contract.php` relative to the plugin root. Execute the native PHP/SQL matrix described above when matching engines and Docker are available.

A hermetic JavaScript/V8 replay tests source contract behavior but does **not** certify Node module resolution, native PHP, real WordPress provider registration, external browser sessions, GitHub Actions, or Staging runtime. Require recorded SHA and actual process exit statuses. Any missing native environment or queued CI is **BLOCKED**, not PASS. Always re-evaluate if PR #258 HEAD advances.
