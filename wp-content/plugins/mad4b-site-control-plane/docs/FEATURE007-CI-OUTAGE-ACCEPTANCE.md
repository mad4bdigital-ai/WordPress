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
