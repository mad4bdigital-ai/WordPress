# MAD4B Elementor/WPML Localization Integrity — Staging 2026-10-31

Source-backed Staging-first design integrity, display eligibility, and translation task lifecycle.

## Operational scope

- Site: `tours.dev.mad4b.com`; source locale EN, target locales AR/FR/DE/ES/IT. New source writes happen only in Staging.
- Existing MAD4B Content Readiness scanner is extended via strictly validated `loc_...` provider fingerprints; old DFSB provider contract remains supported.
- Scan only published frontend pages, published tours/products, and Elementor templates referenced by published pages; do not create 500+ meaningless draft-template translation jobs.
- Scan actual Elementor tree structure (node IDs, parentage, order, type); confirm H1 visibility and visibly untranslated widgets by isolated public browser.
- One central source-owned issue and bounded, deterministic MCP Client job per source, locale and gap type; retries and readback are explicit.
- Exact-host WP-CLI scheduled scanner and readiness reconciler every 30 minutes, browser sample every six hours. Host policies require installed site profile + environment + database + UUID. No WordPress `--user` assertion.
- On source Elementor save, propagate only matching existing widget structure and visual style settings; locale-specific text, media, templates, and dynamic conditions are not overwritten. Missing widgets require translation before becoming visible. Backups saved in non-autoload WordPress options with precise rollback identifiers.
- Registers read `mad4b/localization-jobs` and authenticated guarded claim/ack abilities for the connected MCP Client. A queued job does **not** mean the client has connected or translated. The client owns generation, and the ack verifies fresh WPML source/target readback before status `verified`.
- Scope of missing H1 browser evidence: Home has visible H1, Plan Your Trip is missing H1. Provenance: browser scan timestamp and source page IDs.

## Deployment & QA

Install the single MU module on the exact site and patch the MAD4B Content Readiness class through the authorized governed staging deployment. Install the two source cron entries only on this particular VPS with the exact site, database and profile binding.

Check:

```sh
wp mad4b localization status
wp mad4b localization preview --source=35138
wp mad4b localization scan
wp mad4b readiness plan
```

Use the plan-confirmed `wp mad4b readiness run` only after exact host registry binding passes. Manual source-locale style-only apply uses `wp mad4b localization sync --source=<id> --confirm='SYNC ORIGINAL STYLES ONLY'`, and saves rollback options.

### Acceptance evidence

- Existing MAD4B Content Readiness ledger recorded page-level H1 issue and per-locale Elementor structure gaps.
- Pilot: Elementor template `25927` applied shared styles/order to AR `40536`, DE `25996`, FR `25998`; before/after hashes of all tracked translatable widget text were identical, 3/3.
- Browser found untranslated rendered AR text and confirmed missing translations for published template `54880`.
- New jobs stay queued until an authenticated MCP Client claims them. **No background LLM or client-side translation execution has been claimed.**
- Never automatically copy source English text into translated pages; do not certify commercial or Production readiness from structural sync alone.

## Rollback

1. Disable site-specific cron entries.
2. Restore pre-change Content Readiness PHP from `/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/elementor-localization-20261031/before`.
3. Remove the additional MU file, only after verifying it has no pending in-flight writes.
4. For affected target, restore exact pre-change `_elementor_data` from named `mad4b_layout_backup_*.` Verify after write and purge targeted Elementor cache.
5. Preserve the diagnostic issue/event ledger for incident analysis; do not delete customer content.
