# MAD4B Company CAS — Release execution V3 / 2026-11-12

## Executed and verified (Central + Wayfatours only)

1. forecast_wordpress_postimage.php is a SOURCE-OWNED, read-only WordPress CAS projection script, executed through authenticated WP-CLI by an authorized administrator. It requires matching canonical live resource evidence and computes projected after-image by replacing exactly one allowed metadata value IN MEMORY only. Uses root-owned target CAS policy and cannot create Original Job, Grant, or certificate.
2. Wayfatours Tour Rate wordpress.post:61152, rate_title Meta ID 1668934, raw value empty. Source-evidence fingerprint reproduced exactly; proposed preview value produced an explicit after-image fingerprint but was NEVER written. Numeric single_price, unregistered metadata and nonexistent resource were rejected by source-owned policy.
3. Original 305 domain_intent mismatch was corrected in source at 0c8ca35b94: Original 305 authorizes the delegation lane operations.write; the pinned command and per-operation signed grant authorize the CAS provider/resource. Signed first-party admission was not relaxed.
4. Original Company Execution Gate read-only preflight was fixed to classify newer Git postimage as RELEASE_REVIEW_REQUIRED rather than call confirm_plan with expect_preimage=False against uninstalled source. Fix is in ops/dynamic-operation-registry-v1/integrations/reviewed-source/verify-current-release-noop-v2.py and was deployed to the VPS under /opt/mad4b-company-provisioning/reports/original313-operation-parity-20261110/verify-current-release-noop.py after an exact preimage SHA check and protected backup.
5. Restart of mad4b-company-execution-gate-reconcile.service succeeded: all ExecStartPre and the read-only final scan completed. Output retained general_write_certified=false, state=P0_BLOCKED, outstanding tasks and current Release REVIEW_REQUIRED. Source drift was NOT authorized, hidden, rolled back or published.
6. Central and Wayfatours HTTP endpoints remained healthy and old company.code.release Original 313 verifier stayed operational.

## Source release and first-party authority — still not deployed

Version V2 original source candidate includes the pinned CAS Handler, Preclaim, Finalizer, independent Readback, registry and native Worker adapter. This V3 adds a separate read-only postimage forecaster and reviewed source drift observer. All mutating root components are SOURCE-ONLY and NOT enrolled into production Original 306/313.

- Active Original 318 Source Guard remains DEFER: UNKNOWN_RELEASE_SOURCE_DO_NOT_OVERWRITE because current live 313b is newer than its vetted source. Correct fail-closed behavior until a new independently approved source baseline is enrolled. The Source Guard was not patched, bypassed, disabled, downgraded or marked successful.
- No first-party signed source approval was issued. No operation-specific CAS grant, Original 305 Job or Original 313 CAS receipt exists. Three existing dynamic registry providers remain unchanged.
- No price, Tour Rate content or company customization data was written; no other company touched.

## Last gates for production CAS Apply

- Independently approve/publish exact current source through Original 318 while retaining historical registrations and source guards.
- Enroll CAS operation, Original 306 signed Preclaim and Original 313 independent Finalizer AS ONE governed release, plus source-owned Finalizer operational-ready record.
- Authorize real Original 305 operations.write Job with exact company.resource.cas.apply command SHA, Source Plan, target origin and specific Broker-signed grant.
- Supply a genuine approved new rate_title value and expected after-image, re-read live post/meta row before Apply and reject any drift.
- Run exactly one CAS transaction, immutable Host receipt, independent fresh WordPress postimage and legitimate signed Original 313 finish. Never retry an uncertain Apply.

No production CAS mutation or Original 318/313 certification may be claimed before these gates.
