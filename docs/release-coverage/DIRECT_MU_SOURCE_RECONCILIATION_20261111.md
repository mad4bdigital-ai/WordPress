# MAD4B Direct MU runtime — local Git source reconciliation

Date: 2026-11-11.
Live source: Tours.dev Staging WordPress /wp-content/mu-plugins direct regular files.
Git candidate: isolated worktree only.

## Coverage correction

The eight-component plugin audit did not cover direct must-use PHP modules. A separate read-only top-level MU inventory identified 182 directly stored executable PHP/JS/CSS files on the live site, of which 161 were absent from the full WordPress candidate and three differed despite having the same path.

The 161 absent files were copied into Git with their exact source SHA-256 and Git blob ID, under their original MU path. All are PHP, totaling 1,773,960 bytes. None of the three existing divergent files was replaced:
- mad4b-media-composite-integrity.php
- mad4b-tour-media-reconcile.php
- mad4b-unified-media-apply.php

Those media/Content Jobs source differences require semantic reconciliation, not a full copy from a single environment.

## Acceptance and safety

- All 188 local top-level MU PHP files passed php -l in an isolated PHP 8.5 runtime with network disabled. The 161 new-source copies are included.
- Five local source-provenance tests PASS.
- tools/verify_direct_mu_source.py checks source paths are direct-only, no traversal, content digests and no authority flags.
- tools/audit_direct_mu_live_coverage.py does not traverse or follow 21 live top-level MU symlinks; it reports no direct regular-file paths absent, but three content differences.
- Importing source to Git does NOT activate these must-use plugins. They would be activated by a deployment into wp-content/mu-plugins. Therefore no automated or manual installation into any company is authorized by this capture.
- Plugin source code, runtime enabling/disabling policy, WordPress options, enrollment grants, SMTP, payments and booked reservations on all live sites were not changed.

This first-party source snapshot includes operating components such as runtime routing, cache, booking, source tours, filters and Content Jobs. It is not a certification of their combination and does not resolve the original Host Authority signer or runtime publisher.

**Release state: HOLD**, pending remaining plugin/MU source semantic gaps, isolated runtime QA, original approved publication, and independent signed Original313 readback.

## Later source: optional Extras/Transportation visibility and lazy loading

The separately reviewed source module mad4b-tour-optional-sections-lazy.php was recovered as the 162nd direct MU PHP path. Its existing Elementor render guards and conservative native JetEngine lazy eligibility passed an isolated 20-case PHP fixture. It was NOT installed or activated on a site by this Git capture, and must be governed as executable code before release.
