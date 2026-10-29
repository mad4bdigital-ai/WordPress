# Company Kit navigation governance — Wo Vacation staging

## Why this governance gate exists

The active Elementor Kit `60050` has canonical `Primary`, `Secondary`, `Text` and `Accent` system tokens, plus a **unique** navigation header surface token `bnav001`. A live source census identified **13 duplicate custom color IDs**, often with conflicting values; for example `e14cd5e` appears with both `#B48A46` and `#F6AE22`. **The duplicates are not safe as reusable design contracts.** Do not copy a broken Kit or legacy overrides to a new company.

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

### Explicit limitation

Current 13 duplicated custom tokens remain in Kit because they may be referenced by unrelated historical pages. An authorized, source-backed reference census and separate design review are required before de-duplication. Our original UI improvement is active on Staging only; PR for production remains DRAFT pending CI and explicit approval. A source-controlled audit script is a deploy gate **when invoked**; it does not automatically intercept every manual Elementor save in WordPress. A future approved save-time extension would be needed for global editor enforcement.

No new backup, no Production changes, no customer/pricing mutations. Browser-level hover and mobile drawer interaction still require visual QA with an actual browser; static/HTTP/PHP/JS validation alone is not that claim.
