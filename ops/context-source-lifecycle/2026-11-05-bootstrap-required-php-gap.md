# MAD4B Control Plane — Required PHP Source Closure Gap

**Observed 2026-11-05**, `tours.dev.mad4b.com` Staging vs PR #760, repository `mad4bdigital-ai/WordPress`.

## Status

- `mad4b-site-control-plane.php` references **494** literal `require_once MAD4B_SCP_DIR . '...'` PHP dependencies, including 31 files absent from the PR source tree and absent from `master`.
- All 31 candidate paths exist as regular files in the currently running Staging plugin, not symlinks. Combined size: **419,519 bytes**. PHP lint in the active Staging PHP container: **31/31 PASS**.
- Initial read-only scan did not match the checked PEM/private-key or high-entropy literal credential-assignment patterns. This is not a substitute for formal secret and source-ownership review.
- GitHub CI `MAD4B Runtime Integration` fails with a fatal `require_once` on the first missing file, `class-mad4b-scp-site-lifecycle-rest.php`; previously the `MAD4B Plugin Package` could produce a misleading success despite this absence.
- A direct-source enrollment attempt from the VPS into GitHub was **blocked by safety controls before file upload or commit**. No alternative transfer route was used and **none of these 31 source files has been added** as part of that attempt.

## Missing distributable modules

1. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-lifecycle-rest.php`
2. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-readiness-alerts.php`
3. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-frontend-impact-priority.php`
4. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-readiness-integrations.php`
5. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-readiness-scopes.php`
6. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-readiness-preactivation.php`
7. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-hub-jetengine-consumer-governance.php`
8. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-dfsb-environment-policy.php`
9. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-unified-gate-funnel.php`
10. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-background-agent-admission.php`
11. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-background-agent-dispatch.php`
12. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-guided-continuation.php`
13. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-cli-execution-context.php`
14. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-cli-readiness.php`
15. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-execution-authority.php`
16. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-authority-auto-reconciliation.php`
17. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-authority-reviews-bridge.php`
18. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-provider-installed-artifact.php`
19. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-ai-task-routing.php`
20. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-core-ai-governance.php`
21. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-local-ai-governance.php`
22. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-local-ollama-ability.php`
23. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-core-connector-vault.php`
24. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-native-settings-locator.php`
25. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-adaptive-interaction-ui.php`
26. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-global-runtime-lifecycle.php`
27. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-reconcile-skill-set.php`
28. `wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-local-brand-draft-adapter.php`
29. `wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-dfsb-adapter.php`
30. `wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-chatbotx-provider-adapter.php`
31. `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-local-transport-policy.php`

## Corrective controls now committed

- `tests/static-required-source-closure.py` verifies literal PHP loader dependencies and reports missing or unsafe paths.
- `.github/workflows/mad4b-control-plane-package.yml` now runs the closure check **before** packaging.
- `.github/workflows/mad4b-plugin-package.yml` now runs it before creating the ZIP.
- Neither check grants source ownership, publishes Staging runtime files, disables the loader, or marks a release ready.

## Safe completion contract

1. Resolve source ownership/provenance for each of the 31 modules through the approved source publishing channel.
2. Bring their exact source revisions into a reviewed Git branch, retaining their dependency order and detecting hardcoded secrets or material from other tenant scopes.
3. Verify Git blob/source SHA and PHP syntax for each; run the full installer smoke test in a fresh WordPress environment. Confirm all 494 loader dependencies resolve.
4. Make the two package CI checks, runtime integration, Context Authority regression and Site Control Plane package checks PASS on the **same exact PR HEAD**, then follow normal release verification separately.

**Conclusion:** Source completeness and clean-install release acceptance remain **blocked**. Runtime Staging is currently healthy, but Staging availability is not proof the GitHub distributable can bootstrap.
