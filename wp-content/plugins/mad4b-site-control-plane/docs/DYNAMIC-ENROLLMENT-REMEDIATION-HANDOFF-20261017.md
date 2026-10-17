# MAD4B — Dynamic Enrollment Remediation Handoff
Date: 2026-10-17
Repository: `mad4bdigital-ai/WordPress`
Scope: PR #403, code/docs/tests only, Draft. No Staging or Production mutation.

## Fixed
- Discovery now bounds only first-party `mad4b/` candidates, not the aggregate WordPress abilities registry. A registry with more than 2,048 unrelated abilities can still expose the trusted owner operation.
- On WordPress 7.1, authorization-critical discovery reads the real `WP_Abilities_Registry` record, not an independently filtered `wp_get_abilities()` result. Per-ability `wp_get_ability(name)` identity remains enforced.
- A verified first-party Step-up tool is not automatically a generic Enrollment target. The broker requires an explicitly registered `governed_enrollment_broker=true` and `broker_dispatch_policy=owner_attestation_v1`, an owner-intent declaration, and the existing exact Staging/OAuth identity checks. Other Step-up operations remain independently governed.
- The exact registered execute/permission callback pair is included in the dynamic operation's registration digest. It uses relative source identifiers, never machine-specific paths. Callback substitution between two first-party functions invalidates prior plans.
- Full-scope Native Hybrid functional acceptance requires real observed filter interaction if the captured page contains filter controls. Unrequested frontend-only smoke does not require filters. Neither is a signed independent browser certificate.
- Stale WordPress-native WP-Cron smoke (30+ minutes) can be diagnosed without automatic retry. Manual recovery requires Staging admin + CSRF nonce + exact job ID + absence of scheduled event. It marks the old read-only job abandoned and releases only its matching lock; it never replays or grants release authority. A failed lock deletion is resumable.

## Verified on authorized local device
- Independent exact-head worktree: `C:\Users\nagyx\Documents\MAD4B-PR403-DeepFix-20261017`.
- PHP 7.4 and 8.3: six targeted runtime tests per version PASS, including owner intent, Enrollment transport, dynamic callback/metadata denial, full-scope filter gating, and stale Cron lock recovery.
- PHP 7.4 and 8.3: complete `mad4b-site-control-plane` PHP syntax lint PASS, 1,106 files per version.
- Nine related Python source/governance contracts PASS.
- Feature 007 source-binding verification: 10 manifests, 227 bindings, drift_count=0.
- Docker fixture validation is NOT equivalent to a live WordPress `tools/list`/OAuth owner exercise or GitHub required-check completion.

## Mandatory remaining Staging acceptance
1. Wait for exact-new-head CI and resolve any failures; leave PR Draft until required checks complete.
2. Verify package ZIP SHA-256, embedded provider version, full build provenance and rollback artifact.
3. Confirm current runtime MCP Adapter 0.7.0 on both Staging sites remains intact. The repository's embedded package certification may still be 0.6.1; no downgrade is implicitly approved.
4. Deploy the exact reviewed ZIP to one Staging site after verified backup. Inspect site/origin/build digests, actual WP Abilities registry and the MCP `tools/list`/ `enrollment-discover`/ `enrollment-info`/ `enrollment-execute` path.
5. Use a **fresh owner-authenticated OAuth step-up** to approve the exact source head, build, plan digest and owner decision. Do not reuse a prior owner receipt or silently elevate permissions.
6. Read back current Write-only candidate binding and inspect Operation Journal, Audit v2, Skills state. Then test Elementor and JetSmartFilters on disposable targets with reversible readbacks; WPML translation-link checks remain a separate scope.
7. Repeat for the second Staging site. Production/Developer/Breakglass and independent signed browser release are out of scope.

## Remaining risks
- GitHub hosted runners may stay queued or find new integration failures; the code is not certified until they pass.
- Core Abilities filters including WP 7.1 `wp_pre_execute_ability` require real integration test coverage to confirm permission and execution-fence behavior.
- Native WP-Cron can't guarantee schedule execution if WordPress Cron is disabled or the host scheduler is unavailable. Reconciliation never invents success or resumes an uncertain mutation.
- Native frontend diagnostic request costs and unrelated theme/plugin queries still require performance sampling against an exact previous Staging release baseline.
