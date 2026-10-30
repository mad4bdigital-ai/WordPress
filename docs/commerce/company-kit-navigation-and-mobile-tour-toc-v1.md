# Company Kit navigation governance — Wo Vacation staging

## Why this governance gate exists

The active Elementor Kit `60050` has canonical `Primary`, `Secondary`, `Text` and `Accent` system tokens, plus a **unique** navigation header surface token `bnav001`. A source census initially identified **13 duplicate custom color IDs**, often with conflicting values; for example `e14cd5e` appeared with both `#B48A46` and `#F6AE22`. **These duplicates were reconciled on 2026-10-30 on Wo Vacation Staging** after public CSS confirmed the live winning value for every ID. Do not copy the demo Kit or inherited brand palette to another company.

## Actions performed on Staging

- Edited **only `settings.__globals__` palette references** in six WPML single-tour content templates (`24784,42179,35121,35123,35119,40121`). **54 token references** were switched from ambiguous colors to semantic Kit tokens.
- Original Elementor `nav-menu` widget `9757cf2`: normal text and toggle `text`, selected/active/pointer `accent`, dropdown/toggle surface `bnav001`, divider `accent`. The widget itself, menu ID, hierarchy, typography, responsive settings and all post content are unchanged.
- Original Elementor `table-of-contents` widget `b29a19b`: text `text`, active/border/marker `accent`, surface `bnav001`; no structural changes. Native Elementor rendered CSS shows `var(--e-global-color-text)`, `var(--e-global-color-accent)`, `var(--e-global-color-bnav001)` after cache refresh.
- **Source-corrected header navigation:** The visible desktop header is **Elementor Mega Menu `6072b3f`**, *not Astra's* `#ast-desktop-header`. All six translated headers `26212,43484,43486,43488,43490,43492` previously mixed hardcoded local colors (`#202f59`, `#DC834E`, `#47729E`) and custom `Cairo` typography with partial Kit links. Removed **24 literal color settings and 36 independent font settings**, and bound the native Elementor Mega Menu normal/hover/active/toggle states to Kit `primary`, `accent`, `secondary`, plus `globals/typography?id=text`. Menu items, destination links, dropdowns, widget ID and child hierarchy unchanged. The underline CSS now targets `.elementor-location-header .elementor-element-6072b3f .e-n-menu-title > .e-n-menu-title-container::after` with Kit Accent, keyboard focus and reduced motion support. Old, unrelated Astra selectors are not treated as proof that header hover works.
- Client desktop header's misleading **Market** presentation text is replaced at Elementor HTML widget render time by WPML `Passport` / `جواز السفر` / `Passeport` / `Reisepass` / `Passaporto` / `Pasaporte`. Mobile's actual **Country** shortcut remains Country. Actual `market_country` and `nationality` fields and pricing/visitor preference persistence remain unchanged.
- Desktop navigation underline and hover/focus states use `--e-global-color-accent`/`--e-global-color-secondary` (not fixed hex). Active state remains visible. Existing native menu markup retained.
- On screens <=767px, the existing tour TOC gains a progressive-enhancement `<details>` disclosure: initially closed with an item count, **Overview**, **Experience & itinerary**, **Booking essentials**, **Explore more** subgroups. It is populated exclusively from Elementor's own existing anchor links. Scroll body capped at `58dvh / 440px`; after clicking an anchor the drawer closes. WPML labels for six languages, keyboard semantics, anchor IDs preserved. On desktop, the original native Elementor TOC remains visible; injected mobile UI stays hidden. If the native TOC has not populated valid links, it is never replaced.

### Styling policy

All company brand colors and inherited navigation typographies must originate in the **current company's Elementor Site Kit**. Component CSS may set its own *layout geometry*, including spacing, shape, height, and scroll behavior, only under a reviewed responsive component. Company branding must not be hardcoded in plugins, MU CSS, templates or placeholder text.

Source governance is in `governance/ui/elementor-kit-navigation-tokens-v1.json`. The read-only validator `scripts/audit_company_navigation_kit.php` can run through the connected site's `wp eval-file` to verify the live Kit, all six tour-content template bindings **and all six actual Mega Menu header bindings**. It **flags current legacy duplicate IDs** without deleting them. New-company admission uses `MAD4B_NEW_COMPANY_ADMISSION=1` to **fail closed** on duplicate IDs. Existing-site legacy exceptions are temporary audit debt, not approvals to add new invalid references.

### 2026-10-30: Completed targeted palette reconciliation and live save governance

**Source proof:** all 13 duplicated IDs were present in rendered Elementor CSS exactly once, and each rendered value matched the **LAST** entry in Kit `60050`. A read-only `wp_postmeta` census confirmed the IDs are widely referenced across published JetEngine and Elementor templates; blindly renaming them would break existing pages.

**Applied ONLY on Wo Vacation Staging:** the exact source-controlled `scripts/reconcile_wo_staging_kit_duplicates.php` was run first as DRY RUN (no writes), then with `MAD4B_KIT_DEDUP_APPLY=1`. Exact host / DB / environment / public brand / Kit ID and full 13-color known winners were mandatory. The script retained the last entry per color ID, removing **37 redundant rows**, so the Kit custom palette changed **56 → 19**, with **zero duplicates**. The Kit write was an exact compare-and-swap inside a database transaction, read back after write, and Elementor's CSS cache regenerated. The public tour page then confirmed **all 13 CSS variables retained identical visible color values**. No template, post, customer, or order fields were modified; no new backup was created.

**Mandatory live write guard:** `wp-content/mu-plugins/198-mad4b-elementor-kit-integrity.php` is installed on Wo Vacation Staging, and commits to source control. It enforces no duplicate IDs, valid hex, unique `bnav001`, and exactly one of `primary/secondary/text/accent` on:
- `elementor/document/save/data` for the ACTIVE Kit, rejecting invalid editor submissions before saving.
- `update_post_metadata` and `add_post_metadata` when the active Kit `_elementor_page_settings` is being persisted through WordPress APIs.
- `rest_request_before_callbacks` for **`POST /mad4b/v1/company-activation/commit`**, rejecting native company-operations activation when its active Kit is invalid. The existing cookie/nonce/owner/confirmation authorization remains unchanged.
- An explicit `mad4b_kit_integrity_admission()` callable for governed onboarding pipelines.

The isolated tests validate valid/invalid Kit writes, independent post/other route boundaries, rejection on duplicate ID, and company-activation guard. WordPress runtime smoke confirmed a simulated invalid pre-write is denied while the live Kit remains unchanged. The read-only Kit audit now returns **COMPLIANT**, including all six shared tour-content templates and six Mega Menu headers; strict new-company mode passes on this repaired Kit.

**Precise provisioning boundary:** The identified native Control Plane activation endpoint configures roles and company operations **inside the existing WordPress site**. It is **not a verified external new-site provisioning pipeline**. Future separate WordPress sites must invoke `mad4b_kit_integrity_admission()` as a required admission check in their actual installation/provisioning controller; do not claim that controller is connected until independently observed. Direct raw SQL writes bypass WordPress filter hooks, so governed deployments must not use unreviewed direct Kit SQL mutations.

**Remaining release limitation:** Browser-level hover and mobile drawer visual/touch QA remains unverified. PR source and tests are not merged to master or deployed to Production; no Production change occurred.

