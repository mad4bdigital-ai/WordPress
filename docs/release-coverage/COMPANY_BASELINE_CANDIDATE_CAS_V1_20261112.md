# MAD4B Company Reconcile: Baseline Candidate and CAS Admission V1

Date: 2026-11-12. Scope: Central source tours.dev.mad4b.com with Wayfatours Production as the sole resource-evidence target. No other company enrolled, cloned, or modified.

## Delivered

The Company Repair Jobs engine now has an administrator-only read-only baseline review endpoint at /wp-json/mad4b/v1/company-repair/baseline-review, a matching Company Repair Jobs UI button and an exact scoped CLI baseline-review command.

A review uses the existing journal ID; it never creates a journal. It checks current company, environment, UUID, canonical origin, source plan, provider, immutable resource binding, ownership, before-image digest, resource manifest and company-preservation digest. Freshness is bounded to 300 seconds. A stale, unmatched or unauthenticated readback produces a HOLD, not a fake baseline.

A candidate displays the recorded before and manifest digests only as unsigned planning evidence. It does NOT generate expected_after_sha256, rollback_sha256 or a restorable rollback artifact. The review never signs Original 313, submits Original 305, records a baseline or mutates a target.

The implementation reuses a single source Work Plan assessment per operator baseline request, instead of independently re-evaluating the same company policy during resource preview and baseline review. This also reduces repeated source inspections in resource creation and preflight.

## Critical execution safeguard

An Original 312 signed readiness status combined with an imported unsigned snapshot does NOT establish executor-time before-image compare-and-swap (CAS). Prepare now returns EXACT_EXECUTOR_PREIMAGE_CAS_HOLD unless that independent precondition is verified. The current provider does not supply such verification, therefore executor_time_before_image_cas_verified is false.

The gate is deliberately fail-closed. The general provider registry remains dynamic; it does not add a static incident-to-operation map, and no Original job is created by this module.

## Verified tests

35/35 baseline candidate assertions, 35/35 mocked resource lifecycle assertions, 29/29 snapshot store assertions, 19/19 scoped 312 structural assertions, 52/52 resolver assertions, 67/67 dynamic provider and journal assertions, 17/17 structural Original binding assertions, 15/15 queue assertions, 35/35 source policy assertions, 16/16 target WordPress double-read and fail-closed encoding assertions. Total: 320 source-level PHP assertions. The target reader source was not modified in this increment.

Administrator page JavaScript was parsed by Node, including the baseline review button; no innerHTML is used. PHP source and live lint passed.

## Deployment, acceptance and limits

Only two central plugin PHP files were deployed: the new baseline review class and updated Repair Jobs include. Both matched the source by SHA256, were installed atomically as root:root 0644, and preserve the old Repair Jobs source in the separate reports directory. No Wayfatours WordPress code/data/pricing, WooCommerce, WPML, Stripe or other company data was changed.

Live HTTP: central Start Company schema 200; unauthenticated baseline review REST 401. The privileged central WP-CLI baseline-review acceptance check was attempted, but STAGING_WPCLI_BUSY blocked the call. Its actual authorized live output is NOT verified yet. The existing exact resource child journal for wordpress.post:61152 is not changed by this deployment.

Still HOLD: independently signed Origin target snapshot, restorable rollback bytes, governed expected after-image, executor-time CAS provider contract and proof, Original 305 execution, Original 313 for this resource, Original 318 signed release, end-user pricing and checkout acceptance, and large-scale pagination beyond 64 child resources per incident.

## Rollback

Restore the exact old Repair Jobs include from /opt/mad4b-company-provisioning/reports/company-baseline-review-v1-20261112/preimage/ then remove the unused new baseline review module. Keep all central repair audit journals and snapshot records. No Wayfatours content rollback exists because no target content write occurred.
