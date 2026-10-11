# WordPress-native source worker — Feature 007 (Staging only)

The feature introduces an optional **PHP/WordPress build worker**, separate from
the existing external `standalone_source_build` runner. It is not enabled as
the default release channel, does not execute downloaded source and does not
authorize deployment.

## Abilities

- `mad4b/wp-native-build-capabilities`: read-only resource and security checks
- `mad4b/wp-native-build-plan`: exact commit and Site Profile-bound plan
- `mad4b/wp-native-build-start`: owner / OAuth step-up / central approval; opt-in
- `mad4b/wp-native-build-status`: source download and build status
- `mad4b/wp-native-build-export-chunk`: enrolled-owner-only, exact SHA and
  bounded 8 KiB chunks; caller recomputes final archive SHA-256

## Execution

1. Enrolled Staging owner enables existing **WordPress-native candidate mode**.
2. Discover capability, plan one immutable SHA, approve the exact start.
3. WP-Cron fetches GitHub commit/tree objects from the pinned
   `mad4bdigital-ai/WordPress` repository and the allowed
   `wp-content/plugins/mad4b-site-control-plane` subtree.
4. Fetch source blobs in bounded batches. Verify SHA-1 Git object identity,
   declared sizes, paths and absence of symlinks/submodules.
5. Store in a private workspace **outside webroot**. Re-check the Site Profile,
   lease deadline and files during packaging. Parse PHP tokens without running
   fetched code.
6. Emit a ZIP with stored entries and embedded provenance. Read back the
   SHA-256 and refuse over-budget archives.
7. Mark **BUILT_UNVERIFIED**. Export remains owner-only. No install, GitHub
   publication, signature or release acceptance occurs at this stage.

Only the current trusted plugin's PHP runs; the new repository PHP files
remain data until independent review, certification and governed installation.

## Explicit restrictions

- Staging only, single-site and opt-in. Production is always denied.
- PHP ZipArchive and a private writable temporary directory are required.
  When unavailable, `capabilities` reports blockers; do not enable `exec`,
  `shell_exec`, arbitrary PHP eval or raw SQL as workarounds.
- Requires outbound GitHub API HTTPS and functional WP-Cron. Network issues,
  host rate limits, truncated trees and unsafe files fail closed.
- `token_get_all(TOKEN_PARSE)` is only a preliminary PHP syntax screen; it
  is **not** native PHP CLI lint, DB integration, browser testing, or rollback.
- ZipArchive output is **not presumed byte-identical to the canonical Python
  package builder**. A separate trusted process must verify/canonicalize
  package identity, execute required native tests, review and sign source-bound
  CI-outage evidence and publish immutable release assets.
- The existing `mad4b/control-plane-selected-head-plan/apply` still requires
  a certified manifest, backup, approval, readback and rollback. The new
  worker does not weaken any existing gate.
- WP-Cron depends on traffic or a configured cron trigger. Operators can
  check status; it is not proof of background completion.

## Test

```sh
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-wp-native-build.php
php wp-content/plugins/mad4b-site-control-plane/tests/wp-native-build-runtime.php
```

Acceptance for any exact PR head additionally requires live Staging run,
canonical archive comparison, external attestation, install/readback and
rollback evidence. **Source delivery alone is not runtime certification.**
