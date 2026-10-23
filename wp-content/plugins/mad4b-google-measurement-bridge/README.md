# MAD4B Google Measurement Bridge — Brand Authority

## Current phase: discovery-only (0.1.0)
Built from WordPress PR #479 exact head ea7b4fcd3b2b8d85caee3eefb0d3ed971c91f971, **without changing that PR**.
A standalone plugin, not automatically enabled, adds two read-only WordPress Abilities:
- `mad4b/google-measurement-inventory`
- `mad4b/google-measurement-plan`

It also registers `google_measurement` with the existing Provider Connection Center descriptive provider filter. No connection is claimed, no Google credentials are stored or accessed, and no remote refresh is enabled. This phase does NOT yet manage GA4 or GTM through the user's Google App Connection.

## Next integration contract
**Binding**: site UUID + exact origin/environment + Brand Authority receipt + user Google OAuth subject + explicitly selected GA4 property and GTM account/container/workspace. Do not reuse Google Drive grants as Analytics or GTM rights.

**OAuth scopes (incremental owner consent)**:
- GA4 reporting: `https://www.googleapis.com/auth/analytics.readonly`
- GA4 configuration: `https://www.googleapis.com/auth/analytics.edit`
- GTM audit: `https://www.googleapis.com/auth/tagmanager.readonly`
- GTM drafts: `https://www.googleapis.com/auth/tagmanager.edit.containers`
- GTM publishing (separate elevated authorization): `https://www.googleapis.com/auth/tagmanager.publish`
Follow Google API operation-specific scope checks before enabling; do not silently broaden OAuth grants.

**Read-only operations to implement through an explicit broker**:
1. list authenticated accessible GA4 properties and GTM accounts/containers;
2. inspect existing GTM workspaces, tags, triggers, variables, templates, versions, consent configuration;
3. audit GTM4WP config and active ecommerce/dataLayer event emitters;
4. query GA4 metadata, existing custom definitions, conversion/key events, linked Ads and event reports;
5. generate source/version-bound preview of a GTM delta, without import or publication.

**Staging gated operations**:
1. create a workspace tied to exact container + environment;
2. create/update only approved GA4 custom definitions, or draft GTM entities with exact resource IDs;
3. re-fetch and compare resources immediately before mutating, reject changed revisions;
4. run GTM preview/validation, then separately approve publish;
5. read back version/container identifiers after publish; enable rollback plan and audit receipts.

**Publish is NEVER implied by write authorization**. Production requires an independent explicit approval.

## GTM4WP compatibility / event ownership
- GTM4WP remains the GTM container bootstrap owner (one snippet per page).
- MAD4B companion is a provider of travel-specific `dataLayer` business events, not a second GTM bootstrap.
- WooCommerce: choose **one** event producer per site profile (GTM4WP or MAD4B) after auditing actual emitted events.
- GA4: one canonical view_item_list, select_item, view_item, generate_lead, purchase per source action; avoid parallel duplicates from enhanced measurement or legacy custom tags.
- Consent: respect real CMP/Consent Mode; no default consent acceptance.
- Do not send personal or free-text form fields to the data layer, event attributes or Clarity.
- Track brand profiles with unique (site_uuid, property_id, container_id) links, never via shared global IDs.

## Acceptance criteria
- Google connection owner reconsent returns an account-bound identity and adequate scopes (not just Drive).
- Brand Authority binding identifies exactly one selected GA4 property and GTM container per Site Profile, with explicit switching.
- Read-only list/diff endpoints work using actual Google APIs with no local token exposure.
- GTM4WP and MAD4B event ownership matrix is verified against Tag Assistant on Staging.
- One lead results in one key event per platform; conversion match and dedup evidence retained.
- GA4 Custom Dimensions read back from Admin API; GA4 events verified in DebugView/reporting.
- GTM changes go through isolated workspace, dry-run preview, separate publish authorization.
- All audit and rollback receipts redact OAuth access/refresh tokens and PII.
- PR #479 local-client behavior remains unchanged.

## Deliberate constraints
This source-only prototype does not initiate the user's Google OAuth consent, implement Google API HTTP requests, connect GA4/GTM, create Custom Dimensions, or publish a GTM container. Those require the real Google App Connection broker and subsequent reviewed implementation. It does not modify any Staging or Production site.
