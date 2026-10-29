# Shared Elementor template company identity: rendered text only

**Scope:** Tourism/Woo Lab company sites, verified on `tours.dev.mad4b.com` (Wo Vacation Staging), 29 Oct 2026.

## Source inventory

The Elementor cancellation text editor contains the literal legacy token pair `[site_title][copyright]`. Neither `site_title` nor `copyright` is a registered shortcode on the audited site. Because it appears literally, the popup leaks internal placeholder syntax instead of identifying the site/company.

- EN popup **35027** `4e38814` and EN popup **35034** `7fc556e`.
- ES cancellation template **30644**, DE **30646**, FR **30648**, IT **39526** (text editor `7f7cd23`).
- The separate accommodation cancellation template **25186** has different financial text and no company-name placeholder. It was not rewritten.
- The tour section **24784** renders **Important Links of – {tour name}** from Elementor's existing `post-title` dynamic tag (`11726ba`). This is product identity, **not the company name**. Never replace it with the company brand or a static tour name.

The legitimate public identity is **WordPress `blogname` for the currently selected, isolated WordPress company site**, aligned with Elementor Site Kit and the organization's public name. `company-communication-operations.company_display_name` is the **transactional email sender label**; it was `Wo Vacation TEST Demo` on this Staging site and must **not** override public branding. Do not use `company-communication-operations` as a cross-company, site-global fallback.

## Reviewed behavior

The source-controlled MU plugin `wp-content/mu-plugins/mad4b-company-template-identity.php` provides:

1. `[mad4b_company_name]` for **explicit future company-name text** in existing template text slots.
2. Render-only compatibility for the **exact legacy adjacent pair** `[site_title][copyright]`, and only for Elementor `text-editor` widgets. The pair becomes the escaped, currently selected WordPress site's public `blogname`. No shortcodes named `site_title` or `copyright` are registered, so legacy footer copyright text remains untouched.
3. If the public name is missing, it produces an empty string, never another company's identity. It does not read customer data, credentials, analytics, mail sender, or content from other company sites.

This MU plugin **does not mutate or bulk-replace `_elementor_data`**, change widget type, HTML markup, popup layout, tab structure, dynamic tour title, product copy, order details or rates. It does not activate providers or grant permissions. The existing Elementor Content-Only governance still applies.

### Company-boundary caveat

This implementation is valid for **one logically isolated WordPress site/database per company** (current platform deployment model). For a **single WordPress site serving multiple company IDs**, WordPress `blogname` is site-global and **must not be used** as a company-scoped value. A separate authenticated `company_id` + `site_uuid` resolver and company-owned persistent store are mandatory before enabling such a mode. Fail closed with no cross-company fallback.

## Financial/legal copy is not identity

The same popup contains a hardcoded 15/35/50/100% cancellation schedule, airline fees, a 5% bank refund fee and other force-majeure/refund promises. These are **commercial/legal tenant data**, not universal marketing copy and not approved by a name replacement. Every newly provisioned company must have its cancellation/children/booking policy and applicable market/jurisdiction verified and bound to its own options or product-specific source. Missing approved terms -> no inherited confirmed legal policy; never present an old company's terms as the new company's contractual guarantee. Any separate effort to hide or replace an unverified policy must be explicitly reviewed, respecting the central template's visual design.

## Evidence and staging status

Read-only source audit: 6 affected published Elementor cancellation templates; 2 legacy shortcodes unregistered; dynamic tour heading already correct.

- PHP syntax and 10 behavioral tests passed against exact source GitHub commit.
- Installed the exact MU plugin bytes on **Staging only**, with strict environment/DB/host check. Source and installed SHA-256: `4cee6c60bcf72f9568c8f736d3086e67e79ef7c2a81279c02186d6a8c41cb57e`.
- WordPress widget filter and explicit shortcode pass in real WP runtime. Direct Elementor rendering of templates 35027, 35034, 30644, 30646, 30648 and 39526: **one Wo Vacation occurrence and zero raw pairs in each**. Template 25186 has no pair and remains untouched.
- **No Elementor layout, postmeta, WPML, cancellation-price source, Production or database write. No new backup**.
- GitHub PR gate remains separate from deployed Staging proof; do not claim Production release.

## Rule for all shared templates

| Content | Canonical dynamic source | Mutation authority |
| --- | --- | --- |
| Public company name | Current isolated company site's `blogname` (text-only resolver) | Company identity governance |
| Footer copyright | Dedicated footer/legal source; never infer from `[copyright]` adjacent to a company label | Site legal owner |
| Current trip name | Elementor current post's `post-title` dynamic tag | Company/product content |
| Cancellation/booking/child terms | Approved target company and product-specific legal/policy source | Commercial/legal reviewer |
| Internal support/CTA links | Target-site authorized URL per company | Company Options Page |
| Logos, fonts, colors, layout | Company-authorized media + shared versioned template design | Separate explicit design approval |
