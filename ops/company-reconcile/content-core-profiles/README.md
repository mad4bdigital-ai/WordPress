# Portable MAD4B Content Experience core profiles

The five platform content experiences (page, post, properties, tours-and-activities, transportations) are a **shared core**. Central-only Hub, Fleet, provisioning, build and research operations stay separately enrolled. Company-specific media operations stay attached to the company site. The blueprint is a source-derived schema, not a cross-company data transfer.

`blueprint.json` is neutral: no tenant UUID, production URL, credentials, post content, attachments, stored rate data, or agent grants. `reconcile.php` runs in an existing WordPress runtime through `wp eval-file`. It is **read-only by default** and refuses to change existing profiles or to widen meta/taxonomy access. For new profiles it computes a fresh site-local `profile_plan()` and applies that exact plan using the original `profile_apply()` function. Post types must be installed; taxonomies are intersected with those actually attached to each site. Both draft-first rules and the original WordPress ability permissions remain in force. Profile revisions are site-local; do not blindly copy the source site's `r2` route onto a company's first profile revision.

To apply in a registered host-runner maintenance window, first back up the target site's `mad4b_content_experience_profiles_v1` option and supply the manifest alongside the script to WordPress CLI. Run without confirmation for planning. For applying **only missing profiles**, set these process-scoped variables and run again:

- `MAD4B_CONTENT_CORE_PARITY_CONFIRM=APPLY EXACT CONTENT CORE PROFILES`
- `MAD4B_CONTENT_CORE_PARITY_SITE_UUID` equal to the site's currently enrolled UUID
- `MAD4B_CONTENT_CORE_PARITY_ORIGIN` equal to the site's current HTTPS origin

The script also requires an exact enrolled staging/production Site Profile, no `--user` impersonation, and ready audit storage. The operator retains a backup, applies a single-site change, reopens WordPress, checks the 15 generated abilities on the `mad4b-write` catalog, and checks no new grants have been created. Existing profiles with different settings cause a *hard block*, never an overwrite. Profiles affect registration, not effective mutation authority: grants, user capabilities, original confirmation tickets, controlled transport, pricing and publication guards still apply.

The initial live application on Wayfatours was executed as a one-time host-maintenance operation with exact-site preflight and backup, not as an authorization or approval ticket. A future bootstrap should invoke the same reconciler from its audited Company lifecycle.
