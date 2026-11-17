# PR #814 — live-source preservation / deferred reviews

Baseline PR head: `26a3fec6d0a31491952a2c1c9f64905925231c22` (17 Nov 2026).
This is a source-level preservation review, **not a release decision**.

## Changes already carried on this remediation branch

- Reuse one read-only canonical MCP class-provenance predicate in `bootstrap()` and `inspect_status()`.
- Import the four exact enrolled-live missing Control Plane files; keep Original305 / Host release execution denial unchanged.
- Preserve the 85-file enrolled-live Company Management plugin under its canonical `wp-content/plugins` path, with pinned immutable file digests.
- Three-way merge the six independently nonconflicting live modifications and test the bounded Drive checkpoint recovery.
- CI remains fail-closed on missing owner attestation and exact Dynamic Skills grants.

## Unresolved: 19 overlapping semantic merges

The historic original source and PR candidate edit overlapping lines. **Do not overwrite the PR head with live bytes or suppress tests.**

- `includes/class-mad4b-scp-agent-grant-lifecycle.php`
- `includes/class-mad4b-scp-company-email-routing.php`
- `includes/class-mad4b-scp-company-local-wpcli.php`
- `includes/class-mad4b-scp-content-experience-media-rights.php`
- `includes/class-mad4b-scp-content-job-structural-qa.php`
- `includes/class-mad4b-scp-content-readiness-alerts.php`
- `includes/class-mad4b-scp-contentjob-structural-cwa.php`
- `includes/class-mad4b-scp-developer-authority.php`
- `includes/class-mad4b-scp-developer-workspace.php`
- `includes/class-mad4b-scp-google-drive-context.php`
- `includes/class-mad4b-scp-operator-grant-convergence.php`
- `includes/class-mad4b-scp-readiness-event-automation.php`
- `includes/class-mad4b-scp-remote-media-rights.php`
- `includes/class-mad4b-scp-research-task-bridge.php`
- `includes/class-mad4b-scp-self-update.php`
- `includes/class-mad4b-scp-skill-provider-discovery.php`
- `includes/class-mad4b-scp-skill-registry.php`
- `includes/class-mad4b-scp-skill-seeder.php`
- `mad4b-site-control-plane.php`

## Unresolved: 9 post-baseline components

These files were not present in the old source baseline used for three-way proof. Compare their independent origins and runtime behavior.

- `includes/class-mad4b-scp-brand-review-routing.php`
- `includes/class-mad4b-scp-drive-scan-cron.php`
- `includes/class-mad4b-scp-presentation-browser-evidence.php`
- `includes/class-mad4b-scp-presentation-integrity.php`
- `includes/class-mad4b-scp-provider-field-intelligence.php`
- `includes/class-mad4b-scp-tour-highlights-v1.php`
- `includes/class-mad4b-scp-visual-seo-strategy.php`
- `skill-seeds/wordpress-cache-performance-governor/SKILL.md`
- `skills/visual-content-semantic-seo/SKILL.md`

## Separate governance / release blockers

- **Connection:** real WordPress 6.9 + latest recovery must execute on the new exact GitHub SHA after CI; local isolated tests are not equivalent.
- **Dynamic Skills:** 18 exact grants absent (15 core, 2 translation, 1 google_drive_context). Never mint or assume an owner grant from source tests.
- **Feature 007:** repository-root feature files require independent governed review; do not weaken `REPOSITORY_ROOT_CHANGE_BRANCH_FORBIDDEN`.
- **Pre-Staging:** approved change-slice manifest needs exhaustive semantic ownership for actual base/head diff; do not replace counts without reviewing affected paths.
- **Release Verdict:** exact-head Owner attestation required separately from CI green.
- **Original305:** historical HOLD and independent Master/Host admission unchanged, no signer or timer enabled.
- **Portfolio:** `ops/mad4b-portfolio-signed-binding-v1` is a limited isolated staging binding patch, **not the full plugin**. Canonical full source is `wp-content/plugins/mad4b-company-portfolio-manager/`; its verified snapshot is `company-portfolio-live-snapshot-20261117.json`.

Do not deploy, sign, approve, overwrite live plugin files, or announce global certification from this branch alone.
