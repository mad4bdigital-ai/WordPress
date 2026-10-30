# Wayfatours — source-neutral design restoration (2026-10-30)

## Corrected contract
Historical blueprint \`elementor-and-jet-design.json\` has \`mode=draft_only\`, and \`foundation-manifest.json\` says \`all_drafts_until_tenant_safe\`. That was an **import quarantine**, never the intended final status for every source-published design. This resolver introduces selective, source-state-aware activation without modifying the historical blueprint.

Identity must be independently pinned to tenant \`wayfatours\`, UUID \`b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b\`, database \`wayfatours_production\`, and HTTPS host \`wayfatours.mad4b.com\`. Active Elementor Kit **4** is preserved. No source users, company options, customer data, imagery, analytics, DNS or checkout authority are imported.

## Execution lanes

**WPML translation restoration** (\`wayfa-wpml-resolve.py\` and \`.php\`) reads the original imported structural blueprint (manifest SHA-256 \`118e418f1ce1e91d019a68cfeab44d2d03cbfa5c536b07e776b0ac804c412006\`) and creates **tenant-native translation group IDs** in Wayfatours only. Source group IDs are used solely for partitioning the members; never copied to target. Source original Elementor post ID 6 is remapped to 70006 due to new company Elementor Kit ID 4. Existing groups are preserved and conflicts fail closed. The first live run restored **470** missing Elementor language rows in **176** new target groups. One existing tenant Kit language row remains: total **471** rows / **177** groups. A subsequent run had **zero** inserts.

**Selective design activation** (\`wayfa-design-activate.py\` and \`.php\`) evaluates original source publication state, target draft state, template type, WPML source language, active brand Kit, original URL/brand strings, external media, source attachment IDs, display conditions, query/shortcode providers, commercial prices/booking and dynamic company options. A small typed allowlist permits only independent \`section\`/\`container\` library artifacts; Header, Single, Archive, Kit, Popup, Jet listing and other auto-applied design types **are excluded** until their own dependency resolver admits them. The exact dry-run plan is hash-bound to the apply transaction. Root-only backup is saved first; SQL update is transactional and read back.

Brand strings can be rewritten only in explicitly selected presentational text keys, and \`https://tours.dev.mad4b.com/account/\` can map to the verified tenant \`/my-account/\` endpoint when it exists. No source media URL is ever replaced with a guessed tenant URL. Source domain references, old brand references in code/non-display fields, unproven media IDs, unmapped links and missing providers block activation. Any qualifying template with old source content must have all references resolved after transformation before it can publish. Zero blind bulk replacements.

## Live acceptance

- 470 Elementor records now have tenant-only WPML mapping, 6 languages; original source TRIDs not reused.
- 15 source-published, brand/source-free library Sections activated on Wayfatours Production; 455 imported Elementor templates still Draft. Existing company Kit 4 remains Published.
- No published Elementor design has a direct \`tours.dev.mad4b.com\` / \`wovacation\` / \`allroyalegypt\` / \`egypttourgates\` reference in its Elementor data at verification.
- Wayfatours and Control Plane public HTTPS 200 with trusted TLS after changes.
- Two protected backups on VPS: WPML prior mappings and exact template pre-change rows, plus source-bound receipts. No data/artifacts with customer records copied to GitHub.

## Remaining governed gates

1. Media rights/provenance + tenant asset mapping for source images (no media files transferred).
2. Per-Company brand field registry and JetEngine dynamic \`jet-option\` bindings; do not read Woo Vacation options.
3. Correct Query Builder IDs and tenant-specific listing providers for JetEngine Archive/Single and source listings.
4. Fluent Forms/form IDs, shortcode consumers, Woo pricing/rates and commerce fields.
5. Elementor Theme Builder conditions, Popup triggers and exactly one company Header/Footer per language.
6. Validate target route for \`Plan Your Trip\` translations (currently 404); \`/account/\` uses the tenant \`/my-account/\` URL.
7. Test mobile/desktop visual rendering, filters and multilingual URLs before enabling the remaining templates.

**NO-GO for bulk publishing the 455 blocked templates.** Content Hub commercial publication remains independently blocked. An operator may run the read-only plan at any time. Production apply is an explicit action with an exact snapshot/backup and cannot be invoked by the status Cron.

## Reproduction on enrolled VPS

\`\`\`bash
python3 /opt/mad4b-company-provisioning/bin/wayfa-wpml-resolve.py --plan
python3 /opt/mad4b-company-provisioning/bin/wayfa-design-activate.py --plan
# Explicit authorized target mutation only:
python3 /opt/mad4b-company-provisioning/bin/wayfa-wpml-resolve.py --apply
python3 /opt/mad4b-company-provisioning/bin/wayfa-design-activate.py --apply
python3 /opt/mad4b-company-provisioning/bin/company-readiness-workflow --refresh
\`\`\`

All steps are scoped to a known existing tenant, not a generic WordPress mutator. The current PR is source preservation, not proof of GitHub governance merge/release. Do not bypass failing checks or reinterpret a live proof as a certified release.
