# MAD4B company-local Content Readiness and gallery advisory

Checkpoint: 7 November 2026; source extension for existing draft PR #764.

## Scoped source changes

- Site Control Plane CLI Execution Context allows only content_readiness.scheduled_scan as an internally enrolled local operation on Staging or Production, without extra production environment flags. Host assertions, if supplied, must still match. Other mutations remain governed separately.
- CLI Readiness keeps fresh plan, due-time check and append-only audit, and fixes the profile digest and accurate production status.
- Content Readiness validates gallery-reuse issues from exactly mad4b-tour-gallery-diagnostics on published tours, with medium priority only; it never creates tasks from those media observations.
- MU gallery adapter resolves imported media URLs to local WordPress image IDs at presentation time. It preserves stored data and reports duplicate galleries. It neither swaps to the featured image nor blocks or removes a gallery on suspicion.
- Wayfatours host-runner snapshot uses plan, exact target validation, due-time check, run, and readback, preserving the existing systemd timer. It cannot authorize other mutations or external tasks.

## Deployment boundaries

The two live sites have different Content Readiness implementations, so the branch retains its canonical implementation and adds only the gallery advisory contract. Do not blindly overwrite either live site from the canonical source without a separate site-specific parity check.

The host runner is a site-specific source snapshot; it must only be deployed through the existing reviewed host service. No DB dumps, credentials, customer data, media assets or generated cache were committed.

At this checkpoint the live Wayfatours provider detects 43 duplicate-gallery tour families, but confirming the official issue registry requires a scheduled scan receipt. Discovery alone does not prove ledger persistence.

Other outstanding visual/Elementor and Content Job changes remain separate reconciliation scope.

## Hermetic tests

- php ops/mad4b-company-live-convergence-20261106/tests/test_unified_readiness_context.php staging
- php ops/mad4b-company-live-convergence-20261106/tests/test_unified_readiness_context.php production
- php ops/mad4b-company-live-convergence-20261106/tests/test_gallery_advisory_runtime.php
- python3 ops/mad4b-company-live-convergence-20261106/tests/test_readiness_gallery_source_manifest.py

These tests do not connect to a live WordPress or mutation API.
