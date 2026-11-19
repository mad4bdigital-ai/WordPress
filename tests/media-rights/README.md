# Consolidated MAD4B source-media policies

This integration starts from the most recent `integration/pr814-821-source-converged-20261115` source. It **preserves** the already merged original fixes for strict trusted dates/policy-mode types, dotted license identifiers such as `cc-by-4.0`, the Wayfatours 49-file company-owned runtime overlay, and the JetEngine rights-archive post-commit cache fix.

The three changed Control Plane guards/adapters are additive security fixes: missing WordPress library rights fields are warnings, explicit restrictions/expired evidence remain blocking in both modes, CC BY/SA is never reclassified as public domain, remote source/content provenance must match. The `MAD4B_SCP_Content_Experience_Media` normalizer is deliberately unchanged because the latest unified source is newer than the temporary release source and already accepts blank local rights and dotted license IDs.

Run the `wpcli-local-library-rights-policy-contract.php` under an authorized WordPress Staging admin context, **read-only**, and the existing `tests/content-media-rights-guard-original-hermetic.php`, `tests/content-media-local-missing-rights-original-hermetic.php`, `tests/media-rights-cct-after-commit-original-hermetic.php` in plain PHP. These are proof of behavior, not a signed release or approval to rewrite Wayfatours.
