# MAD4B public holidays without Google OAuth — remote architecture

## Verified October 24, 2026

- The authorized VPS itself fetched the public Google holiday ICS feeds for Egypt (`ar.eg`, `en.eg`) and the Nager.Date v4 country API (Egypt, US, Spain) over HTTPS with HTTP 200.
- The isolated ETG WooCommerce Local Lab has `WP_HTTP_BLOCK_EXTERNAL=1` with no allowed external hosts. Calls from within WordPress correctly return `etg_sandbox_network_blocked`. Do not bypass or disable that control simply to run a feature.
- The existing Google OAuth in Brand Context remains `dedicated_google`, `configured=false`, `token_unreadable`. That concerns *private/account-owned* calendars, not public holidays.
- `MAD4B_SCP_Public_Holidays` is a read-only, bounded public calendar reader with two explicit allowed origins: verified Google public ICS for Egypt and Nager.Date community API v4 for other suggested countries. It registers `mad4b/season-public-preview` as a permission-checked WordPress Ability. No user-supplied arbitrary URLs, no tokens, and no write callback are allowed.
- The class parses Google all-day ICS events without expanding recurrence rules and Nager.Date national public holiday data. Source dates are proposals, never legal/commercial approval.
- A pure ICS fixture passes; live direct public provider execution *inside the Lab* is blocked as designed. Native access and central tool exposure remain uncertified until governance acceptance and tool catalog reconciliation.

## Proposed fully remote operational method: MAD4B Managed Feed Gateway

1. Run a governed fetcher in a **network-authorized MAD4B service** with allowlisted official public sources, bounded requests, HTTPS validation, response size and time limits, and rate controls.
2. Resolve each *visible* WPML language and proposed country-language market from MAD4B Market Profiles. A proposal may be previewed before approval, but it cannot create a published season.
3. Sign a `mad4b.season-feed-receipt.v1` payload tied to the enrolled Site Profile UUID, source ID, country, year, UTC retrieval time, expiry, canonical content hash and normalized event identifiers. No credentials or secret calendar URLs are embedded.
4. Deliver only via an existing, properly authorized MAD4B governed read/write channel, not through an unprotected WordPress webhook or arbitrary uploads. Verify receipt signature, freshness, site binding and replay deduplication in the destination.
5. Store **pending event proposals** only; explicit admin/authorized-agent review creates inactive season terms. Publishing, Top Bar activation and financial rules retain their own authorizations.
6. Schedule remote fetch and report failures/staleness. If a source disappears or a holiday date changes, make its previously accepted schedule inactive pending review. Never publish from an incomplete provider window.

This gateway is a design specification: it is **not yet deployed**. Do not claim zero-click live ingestion in the local Lab until a certified MAD4B integration gateway or an explicitly authorized domain allowlist exists.

For private calendars, the existing Google OAuth/grant remains necessary. A one-time owner consent or approved Workspace delegation cannot be bypassed by remote browser automation.
