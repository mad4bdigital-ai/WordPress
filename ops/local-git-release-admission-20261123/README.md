# MAD4B Control Plane: Host-pinned local Git release admission

**Date:** 2026-11-23. **Status:** READ-ONLY release admission guard, not a deployable release or a live update-channel switch.

## Desired source policy

1. Use an exact **local host Git commit** as the candidate source (`host_pinned_local_git`), never `origin/master`, a mutable branch, or a naked higher version number.
2. Preserve the currently installed per-site signed build provenance and all scoped company overlays while preparing a new candidate. A local Git HEAD is not necessarily the proven installed build.
3. Build an immutable package. Verify the cryptographic release issuer, exact source SHA, full package manifest, runtime adapter dependencies, and a real Release Verdict. Require the signed behavioral and rollback evidence for the Elementor write provider before any activation.
4. Separate source selection from deployment. Keep automatic Production apply disabled. Back up the existing package and databases; verify actual installation after deploy and roll back on failure.
5. Keep GitHub as review/synchronization (optional) and do not let the stale `rc.104` fixed Release pointer downgrade running `rc.128` sites.

## Verified audit of actual VPS data

- Installed Wayfatours Control Plane is `0.4.0-rc.128`, packaged from recorded `3e6ce498b75390bc900c2c083f92891b8433b774`, with 955 certified package source files. Installed Tours.dev is also `rc.128` but packaged from a **different company source identity** `e18c8090ad217d467296bbfa327fc238799393e0`, with 956 certified package source files.
- Original local candidate `20aab770d21f45442fe09b5ae840a90b7ff8fe5a` is clean and declares `rc.129` but isn't proven descendant of either installed source SHA. Its Control Plane file inventory shares only 754 unchanged bytes-equivalent files with Wayfatours and 793 with Tours.dev; 226/208 common paths differ respectively.
- Newer local branch `fix/host-v150-original-live-staging-source-20261122` (`d23cf0a39d1`) also declares `rc.129`. It has 224 common changed paths versus Wayfatours, and 205 versus Tours.dev. It does **not** derive from the prior local candidate in a direct ancestor relationship.
- In *both* local rc129 candidates, two existing native-update no-regression methods and four execution-authorization safety methods from the actual installed `rc.128` release are absent. These are **source gate failures**; treating them as a harmless file conflict would be unsafe.
- Both local candidates lack `config/central-release-issuer-public.pem` present in the installed certified package. This public trust anchor could be injected by the genuine signing build; therefore the **source tree** is not automatically defective on that absence alone. The final signed build must contain and verify the anchor. Do not copy an untrusted key over the issuer.
- Git `origin/master` at `8030f40a44b...` (2026-11-22) still declares `rc.104`. `rc.129` branches have not been promoted to a vetted immutable shared release.

## Guard operation

`local_git_release_gate.py` is a **read-only** release-source preflight. It checks version floor, installed baseline build-provenance identity, required original safety method symbols, and byte parity for five high-risk PHP files. It supports an optional stricter **final-package presence** check; it does not pretend that PHP method-name presence is behavior proof, and it **never issues a release authorization**.

Example (no WP changes):

```bash
python3 ops/local-git-release-admission-20261123/local_git_release_gate.py \
  --candidate /path/to/pinned-local-git-worktree \
  --live-plugin /path/to/verified-installed-site-control-plane \
  --output /safe/private/path/to/audit.json
```

Exit status `2` means preflight failure, with a precise JSON cause; `0` only means the **read-only structural preflight** passed, not that a package can be deployed. Original signed provenance and browser/behavior/rollback receipt remain mandatory.

## Release-blocking reconciliation slices

1. Preserve the live `reviewed_archive_no_regression` and `trusted_master_release_candidate` functions in `class-mad4b-scp-self-update.php`; review their actual call sites and host-independent integration instead of copying unreviewed function text.
2. Preserve `execution_attempt_sha256`, `execution_attempt_state`, `execution_boundary_verified`, and `local_approval_planner_execution` in `class-mad4b-scp-authorization.php` without weakening current owner-attested authority and the source/provider grants.
3. Resolve the other changed runtime files and the site's non-backup-only files, especially signed issuer, release-policy, Elementor provider and native write checkpoints. Keep company-specific overlays separate.
4. Rebuild and re-certify the now-reconciled local source **at a new pinned commit**, including independently verified original Release Verdict and package signature; never simply increase `rc` metadata or import obsolete GitHub release artifacts.
5. Test staged site builds, execute exact eligible owner-approved per-site promotion, then confirm browser/booking/pricing regressions, site-specific runtime authority, and readback. No live changes are performed by this repository guard.

Three isolated guard tests passed. Baseline audit exits with six missing-function failures for **both** rc129 candidates against **both** installed companies, as expected.
