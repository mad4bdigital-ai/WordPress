# MAD4B local Git release candidate: moving live-source drift gate

UTC date: 2026-11-11. Captured in isolated full WordPress Git worktree.

## Why this gate is necessary

Tours.dev Staging is receiving source changes concurrently with local Git reconciliation. Completing an inventory once is not a proof that the release code still represents the latest live source at later publication time. In particular, plugins and direct MU sources can change independently of the date-filter assets and can introduce financial, permissions, email and Media behavior that must not be merged without contract review.

tools/audit_moving_live_source_drift.py is a read-only, bounded, live-first two-pass code audit. It combines all first-party plugin PHP/JS/CSS sources with directly stored MU PHP/JS/CSS sources, preserving native direct MU symlink exclusions. It records each missing or differing source path, current live SHA-256 and Git candidate SHA-256, grouped by component. A second independent inventory and hash readback reports if the live source changed during that capture window. Each report pins the exact candidate Git HEAD and tree.

This source drift record is not a certification of all original Git branch histories, noncode plugin assets, database migrations, site options, company-specific WPML/SEO/JetEngine data or installed runtime permissions.

## Snapshot at first readback

The new read-only report /opt/mad4b-company-provisioning/reports/unified-local-coverage-20261111/live-source-drift-v17.json recorded 32 first-party plugin source roots, 5 live source code paths not yet in Git and 59 code content differences (including direct MU). The double scan was stable during that readback; this does not claim the source will remain frozen.

Current new source files include two immutable Unified Date v0.3.2 assets, an early-payment benefits CSS file, and direct MU files for early-payment benefits and WooCommerce request email. These features may change quotes, request flow, WooCommerce email messaging and eligibility. They are not automatically safe to deploy and have not been imported. Existing direct MU modules for optional sections and Form6 also have additional live changes that need review.

The previously documented source-only candidate contains the verified 0.3.1 native JetSmartFilters/WCML date bridge and strict Query 310 AND-fail-closed corrections. Its exact functional test evidence remains intact even if the live site is now newer.

## Release gate

A current source drift count is a HOLD signal, not permission to copy all live files or overwrite locally newer branch behavior. A new release is gated on semantic reconciliation plus original authorized Original318 publish/Host Issue-Revoke and independent Original313 signature/readback. Live Ajax/browser acceptance and company-specific data preservation are required separately.

The audit itself never issues grants, jobs, CLI writes, code release, payment requests or WordPress mutations. It reports release_authorized=false by construction. Four unit tests cover unsafe path rejection, fingerprint differences, missing-vs-different classification and fail-closed no-grant semantics.
