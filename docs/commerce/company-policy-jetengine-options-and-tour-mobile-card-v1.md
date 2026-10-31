# Company-owned policies + responsive tour countries — Wo Vacation Staging

**Status:** Implemented and verified only on `tours.dev.mad4b.com` (WordPress Staging, database `etg_commerce_staging`). Source-controlled and awaiting governance/CI approval for Production.

## 1. Mobile country cards: single-tour only

The screenshot showed a **140px** destination card although JetEngine's listing `45928` uses one mobile column. Verified source:

- Single tour `60619`: `/tours-and-activities/epic-egypt-to-dubai-grand-journey-12-days/`.
- Section widget `#single_tour_countries`, source JetEngine Listing Item `45928`.
- Card container `.elementor-element-72ce2a0`: `width_mobile = 140px` and `min_height_mobile = 300px`.
- Responsive fix in `wp-content/mu-plugins/mad4b-tour-country-mobile-card.php` applies only to `body.single-tours-and-activities #single_tour_countries` at `max-width:767px`. Forces the listing, item, inner container and card to parent width, `max-width:none`, `--width:100%`, and one full-width grid column. **Desktop, tablets, tour archives and destination pages unchanged**; no Elementor JSON/template setting mutation. Scope is all single-tour pages, not post 60619 alone.

## 2. New JetEngine Options Page: Customer Policies

Source file `wp-content/mu-plugins/mad4b-company-policy-library.php` registers `mad4b-company-policy-library` through JetEngine's native `register_new_options_page` extension. It appears under the existing **MAD4B Company** WP admin menu as **Customer Policies**:

`/wp-admin/admin.php?page=mad4b-company-policy-library`

Capability: `mad4b_manage_company_operations` (present on Staging roles `administrator` and `master_admin`). The schema is committed in PHP, **not copied wholesale from the demo wp_options data**. The complete page has **65 field definitions**: one informational section + 8 approved/draft categories × (approval, revision, six language fields). Each field is owned by the current isolated WordPress company site.

| Policy category | Dynamic tag policy_key | Source fields |
| --- | --- | --- |
| Cancellation and refunds | `cancellation` | `cancellation_approval`, `cancellation_revision`, `cancellation_en/ar/fr/de/it/es` |
| Children and age rules | `children` | `children_approval`, `children_revision`, `children_*` |
| Booking and deposits | `booking` | `booking_approval`, `booking_revision`, `booking_*` |
| Accommodation | `accommodation` | `accommodation_approval`, `accommodation_revision`, `accommodation_*` |
| Prices and exclusions | `pricing` | `pricing_approval`, `pricing_revision`, `pricing_*` |
| Tipping | `tipping` | `tipping_approval`, `tipping_revision`, `tipping_*` |
| Force majeure | `force_majeure` | `force_majeure_approval`, `force_majeure_revision`, `force_majeure_*` |
| General travel terms | `general` | `general_approval`, `general_revision`, `general_*` |

### Dynamic fields and company boundary

- Native Elementor dynamic tag: `MAD4B Company Policy (JetEngine Options)` / `mad4b-company-policy`; select category via `policy_key`.
- Shortcode alternative for existing text slots: `[mad4b_company_policy type="booking"]`. This shortcode is not an authorization bypass.
- A template displays text only if `*_approval=approved`, `*_revision` is nonempty, and localized `*_<WPML language>` is nonempty. No English fallback of legal financial policy for French, Arabic, etc.; no legacy other-company copy.
- If absent or draft, localized neutral confirmation notice is shown; this is **not a binding booking/refund promise**.
- `{{company_name}}` in approved text inserts the public site name safely; use it instead of hardcoded company branding.
- Store only company-owned content. No PII, external provider credentials, booking/rate data or unverified financial terms in this Options Page.
- **Architecture limit:** this is for one isolated WordPress site/database per company. A single WordPress site with multiple concurrent company IDs must use verified `company_id` and `site_uuid` ACL + separate company persistence; do **not** rely on site-global `get_option` for that model.

### Bound original shared Elementor templates (19)

Only the existing `text-editor` widget's `settings.__dynamic__.editor` binding changed: no widget conversion, deletion, child reordering, layout/spacing/theme changes, or published post titles.

| Policy | Template IDs and languages / purpose |
| --- | --- |
| Cancellation | 35027 EN popup, 25186 EN separate template, 30644 ES, 30646 DE, 30648 FR, 39526 IT, 40603 AR |
| Children | 35034 EN Children popup (previously showed an incorrect duplicate cancellation fallback) |
| Booking | 35039 EN popup, 25184 EN separate template, 40607 AR |
| Accommodation | 35043 EN popup |
| Pricing | 35048 EN popup, 25187 EN separate template |
| Tipping | 35047 EN popup, 25188 EN separate template, 33646 FR, 33648 DE, 33653 ES |

**Live current option values:** The policy option page is newly registered, **no approved values were seeded**. All bound templates have a safe, not-yet-confirmed status until a company's authorized operator supplies and approves actual terms with revision/source. Direct Elementor frontend rendering confirms the safe notice on all 19 template bindings.

**Important remaining integration boundary:** Tour-specific `cancelation_policy`, `booking_policy`, `prices_policy`, `tipping` etc. dynamic postmeta widgets **still exist** and may render their own product-specific terms independently. Approval/precedence for these product-level overrides, external legal certification, rates and booking activation is **not yet implemented here**. Tracking issue [#635](https://github.com/mad4bdigital-ai/WordPress/issues/635). Do not claim all business policies have been certified by the new fallback tags.

## 3. Validation and controls

- Confirmed new options page: registered, 65 fields, company capability and native JetEngine Elementor selector recognizes 64 user fields.
- Custom Elementor Dynamic Tag resolves neutral fallback and supports all six languages.
- PHP syntax and isolated policy tests for source ownership, approved revision, locale isolation, bad type and sanitization.
- CSS PHP lint and mobile-scoping tests pass; public sample single tour HTTP 200 and CSS injected only on single-tour pages, not homepage or tours archive.
- Preserve pre-existing company-name resolver from [PR #634](https://github.com/mad4bdigital-ai/WordPress/pull/634); never rewrite tour post-title Dynamic Tags.
- No Woo/JetEngine rates, company contacts, WPML settings, Production system, customer data, or new backups touched.

### Promotion

Draft PR only pending source review, GitHub checks and owner-approved deployment; no Production mutation. Live Staging files are identical to committed source, subject to per-file SHA verification.
