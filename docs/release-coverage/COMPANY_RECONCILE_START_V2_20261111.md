# MAD4B — Company Reconcile and Start Company V2
Date: 2026-11-11

Scope: central tours.dev.mad4b.com. Wayfatours production was the only runtime target used for a bounded site-local readback. No site cloning, company creation, company option changes, price changes, payment changes, grant or signed release.

## Improvements

- Source-policy workplans now support new-company private bootstrap before site enrollment. Existing company sites use a reconciliation lane and cannot be implicitly re-created.
- Exact identity for each environment includes the company, environment, canonical HTTPS origin and Site UUID. Reports from another environment, even with a similar UUID, never satisfy exact-site readback.
- The plan derives from current source policy: 34 staged checks and 18 incident scenarios at final readback, with independent provider and acceptance ownership. Seven mixed-ownership categories keep company values separate from portable generic definitions.
- The source-plan SHA includes provider owner and policy scope changes, preventing reuse of a prior review against altered ownership rules.
- Company Reconcile can reuse the existing bounded center-to-Wayfa WP-CLI observation as LOCAL_WORDPRESS_OBSERVED_UNSIGNED, without promoting it to Original 313, deployment or grant authority. The Staging request cannot inherit a Production observation.
- Administrator-only REST assessment and on-demand Company Reconcile / Start Company screen. Existing on-demand provider operation readiness is preserved with error normalization when a provider returns WP_Error/non-array.
- Start Company distinguishes a new private bootstrap, existing company reconciliation, and an existing private reservation. It blocks duplicate resource provisioning, but does not impose a new mandatory certificate or OAuth step.
- Completeness includes the four stage groups, including commercial, and links to exact environment operation diagnostics. Old stage PASS hints never replace independent signed site-specific readback.
- Preservation categories: company WooCommerce, SEO, Elementor Kit, Fluent Forms submissions, WPML target translations, tax/currency, payment credentials, media rights and users. Code and generic schemas can only be transferred after semantic matching and authorized execution.

## Verified

9/9 standalone PHP tests passed, and 21/21 central WP-CLI regression tests passed. Central administrator REST assessment returned 200, anonymous 403; Wayfatours Production showed 34 staged checks, bounded WP-CLI observation unsigned; Wayfatours Staging showed NOT_OBSERVED_FOR_REQUESTED_ENVIRONMENT. The synthetic intake evaluator distinguished existing Wayfatours from a new company without creating resources.

The five-operation readiness live preflight was not completed during this work due to the central WP-CLI admission controller returning STAGING_WPCLI_BUSY. An in-process duplicate-reservation simulation was blocked by execution safety checks; it is not counted as a passed test. Both should remain explicit independent checks.

The Company Management main plugin live file differs from the local Git integration baseline due to concurrent development and was not replaced. Only separate include files were deployed; Git records their provenance.

## Open gates

Original 318 signed publication, provider registration for company.code.release, original exact jobs and independently signed Original 313 remain HOLD. Other companies still await their separate reconciliation/Full Clone decisions. No company-site writes were done.

Rollback: center source preimages in /opt/mad4b-company-provisioning/reports/company-reconcile-start-v2-20261111/pre/ and the later dated per-file backups. Wayfatours requires no rollback because nothing there was written by this task.
