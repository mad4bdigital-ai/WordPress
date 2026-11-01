# Wo Vacation — Brand identity leak remediation (2026-10-31)

Staging incident: Arabic header Elementor post 43484 contained the prior company's email, WhatsApp number, maps/location and social profiles while the Wo Vacation brand profile was configured and approved company contact fields were empty.

## Runtime fix
- Exact-source backup retained on VPS: WordPress option `mad4b_brand_isolation_backup_43484_0fbab2ac07ab`.
- Two contaminated Elementor widgets 7c58c5a and e04f82e were removed from Arabic Header 43484 via WP-CLI, leaving all other translated design intact.
- No guessed replacement company email, phone or social links.
- Source-scoped `302-mad4b-brand-surface-integrity.php` uses an exact site UUID, origin and configured brand name to apply a tenant-specific blocked-token policy. Configured on staging in `mad4b_brand_surface_policy_v1`.
- A frontend filter suppresses contaminated Elementor widgets if they recur; the source-backed readiness scanner creates `loc_brand_{lang}` critical alerts, inheriting the original content Readiness ledger and dashboard alerts. Source provider policy is not a global hardcoded denylist.
- MCP ability `mad4b/brand-surface-check` is read-only. Remediation and replacement of contact data remain owner-context tasks.
- Admin or executor should set and verify authentic Wo Vacation contact fields before showing them. No automatic public release inferred.

## Tests
`php ops/qa/mad4b-brand-surface-integrity/tests/source_contract.php` verifies the policy boundary, render guard, real English source issue identity and resolution after repair. Live isolated WP-CLI metadata override of the prior snapshot proved scanner + MAD4B Content Readiness recognized one Critical `loc_brand_ar` linked to source #26212, without writing the old data.

Scope: staging. No Production changes.
