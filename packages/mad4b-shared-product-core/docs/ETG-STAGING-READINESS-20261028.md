# ETG Shared Product Core — Staging admission record

**Source revision:** GitHub Draft PR #541, `0.1.0-dev.20261028` (source-only); test environment: Hostinger VPS Local Lab and ETG Coolify-derived Staging PHP containers. **Status: STAGING BLOCKED.** No production mutation, no customer content migration, no source installed in existing Staging WordPress tree.

## Reproducible read-only check

Within an authorized WordPress PHP container, with its normal working directory `/var/www/html`:

```sh
php < tests/staging-compatibility-preflight.php
```

Or from the VPS without copying the script into a read-only container:

```sh
cat tests/staging-compatibility-preflight.php | docker exec -i -w /var/www/html <authorized-php-container> php
```

The script checks required plugin installation/activation/version, canonical origin resolution, PHP support window, and MU Core presence. It writes nothing. All output is JSON. It is **only a prerequisite**, not behavioral certification of a third-party plugin or the Elementor editor.

## Observed parity — October 28, 2026

| Check | ETG WooCommerce Local Lab | ETG Coolify Staging candidate |
|---|---|---|
| PHP | 8.5.12 | 8.3.36 |
| WordPress | 7.1.3 | 7.1.3 |
| Elementor | 4.3.5 | 4.3.5 |
| Elementor Pro | 4.3.2 | **4.2.3** |
| JetEngine | 3.8.15.2 | 3.8.15.2 |
| JetSmartFilters | 3.8.5.1 | 3.8.5.1 |
| WPML | 4.9.5 | 4.9.5 |
| WooCommerce | 11.2.1 | **Not installed** |
| Active plugins | 103 | 82 |
| Shared Product Core MU loader | Present, Local-only | **Absent** |
| WordPress internal `home_url()` | `https://tours.dev.mad4b.com` | **`http://localhost`** |
| Admission | Ready for *further* integration testing | **BLOCKED** |

The PHP 8.3 and 8.5 source syntax tests and offline contracts pass, but this does **not** establish cross-site runtime parity. Staging's reported internal URL may reflect its container topology; verify its public edge and per-company canonical authority before changing configuration.

**Four recorded blockers:** older Elementor Pro; WooCommerce absent; current internal WP canonical home does not resolve HTTPS; Shared Product Core not installed. The Staging container has a read-only root filesystem; attempted copying of the offline test into `/tmp` was denied, so no files were placed inside it. The read-only PHP preflight instead runs through stdin.

## Release process / gates

1. Explicitly identify which ETG Staging topology is the target, versus the older Coolify-prep instance. Back up and snapshot its **actual** production-equivalent DB and files separately.
2. Converge plugin versions and PHP/extensions through a controlled staging change with tested rollback. Do not silently overwrite Elementor Pro or install WooCommerce into an existing site.
3. Establish per-tenant Service Activation before promoting the Lab-only loader; split ETG-specific query/listing IDs from tenant-neutral product code.
4. Run authenticated Elementor Editor browser load → save → readback (not only `Document::save()` via CLI) plus real nested JetEngine Repeater and filters on the same Staging target.
5. Run WordPress-backed `field-contract-release-gate.php` and `primary-tour-query-context-regression-cli.php` using **that** Staging database and verify zero unsupported bindings. Record exact `git commit SHA`, backup SHA, CI receipts and rollback plan.
6. Require explicit MAD4B Owner Attestation / approval and Release Verdict pass before Production rollout. No automatic Production deploy from this source-only PR.

## Certification status

- GitHub source import: **133/133 Git blob SHA matched**, with frozen `source-files.sha256` manifest.
- PHP 8.3.36 / PHP 8.5.12 package lint: **126/126**, offline contract tests **12/12** each.
- GitHub Actions source gate: passed after adding exact PR Head SHA and per-PR concurrency.
- Local Lab: data-shape and primary tour query regressions passed; a temporary authorized Elementor `Document::save()` test passed and test draft was deleted.
- Logged-in full Elementor browser editor test: **not yet certified**. The public browser page test is not an editor test.
- Original ETG Staging PHP environment: **blocked on runtime parity**, source not activated.
- Owner attestation / governed release: **not yet granted**.

This document is an operational admission record, not permission to mutate the target company site.
