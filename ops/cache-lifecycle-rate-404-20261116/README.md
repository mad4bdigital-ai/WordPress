# MAD4B cache lifecycle — internal tour-rate URL guard

Live baseline SHA256: 7c0124ec9be8ffad09613398d0b821661b3b50352b3717562339e99d0ad5b11e

Patched SHA256: 6ec32f43787cdaab1e2c7c1d3d668a3cbeb1b2ca473b0d037ffb1feff51dd233

Root cause: tour-rates is a stored CPT with public=true but individual public permalinks return 404 on Tours.dev and Wayfatours. Previously cache lifecycle queued those permalinks for purge/verifier, repeatedly checking unavailable pages.

Behavior: preserve related tours-and-activities parent invalidation; do not queue a tour-rates public URL or deletion tombstone. Ordinary public pages remain unchanged. Status-transition and taxonomy invalidation gates stay intact.

Regression command: php ops/cache-lifecycle-rate-404-20261116/tests/test-rate-routing.php wp-content/plugins/mad4b-cache-lifecycle/includes/runtime.php

Deployment: 2026-11-16, manual staged-then-production with exact preimage SHA check, PHP lint, public HTTP checks. Rollback snapshots stored in protected VPS directory /opt/mad4b-company-provisioning/ops/cache-lifecycle-rate-404-20261116/.

Existing failed rows were backed up, then pruned with row transaction plus advisory lock: Tours.dev 122, Wayfatours 66. Previously verified rows retained.

Integration note: cherry-pick or reconcile against the currently selected unified source tree; do not overwrite newer runtime changes or resurrect removed modules.
