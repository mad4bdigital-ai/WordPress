# MAD4B Unified Date v0.2.7 and optional sections — bounded source reconciliation

Date: 2026-11-11. Source site: Tours.dev Staging. Target: isolated local Git integration branch, **not** a live installation.

## Source provenance and CSS cache

The source site now enqueues immutable-file names unified-date-v0.2.7.js and unified-date-v0.2.7.css, rather than reusing the old unified-date-v1 asset names with only a query-string version. This addresses stale CDN/Nginx CSS URLs where query strings are ignored. Both immutable 0.2.6 and 0.2.7 source files were captured with exact live SHA-256/Git blob hashes (4 files), and the mutable v1 legacy assets were reconciled as preserved source.

The WordPress PHP date adapter now references the v0.2.7 immutable assets while keeping the previously verified fail-closed AND helper for archive, JetSearch and Tour Rates Query 310. Existing post__in=[0] never broadens to an eligible rate, and invalid date queries never authorize a price or booking. The single documented PHP semantic source overlay remains checked by an exact candidate Git blob fingerprint. The other 272 Commerce sources match the live source byte for byte at capture.

The v0.2.7 UI provides a compact rate-date field on desktop and full-width control on mobile. Dialog geometry is recalculated for a fixed centered desktop modal or bottom-sheet mobile portal; its source test validates both mode branches, no duplicate native JetSmartFilter, inherited Form6 dates and the original URL/date contract. Browser usability is still unverified.

## Optional tour sections

Recovered the original direct MU module mad4b-tour-optional-sections-lazy.php with exact source SHA. It gates the authored Elementor templates and JetEngine listing grids for extras and transportation **before render** according to the parent Tour's explicit status, relation IDs, publication and component availability. Translation fields inherit only when the current translated option is missing. Editor and unrelated listing widgets remain unmodified.

Deferred building uses only JetEngine's native listing/grid/is_lazy_load and lazy-load/options interfaces with an approximately 650px offset. It is eligible only for parent-bound, published, native post-query listings, with no pagination/load-more or archive templates, in the configured active or canary context. Authentication/editor, AJAX, cron and unknown URL-query contexts are excluded. No custom JavaScript lazy loader or rate/booking executor was created.

An offline fake-WordPress PHP fixture exercised 20 cases for the four option groups, published relationships, editor/AJAX exclusions and canary/active lazy policy. Static source tests validate the native hook boundaries, no payment/post mutation and original SHA. Source inclusion in Git is not a WordPress activation: direct MU files become executable automatically if deployed to the live site, so distribution remains gated.

## Governance and limitations

Neither Tours.dev nor Wayfatours production or staging code, database, plugin settings, bookings, rates, company ownership, currency, WordPress template/post records, user grants or Stripe were changed by this source-only work. No runtime request or mobile browser acceptance is claimed.

The global release remains HOLD until remaining branch/source semantic differences, Original318/313 signed authority, and live AJAX/Elementor/JetEngine/Form6 acceptance are proved.

## Follow-up: optional section CSS 1.2.0

Tours.dev source updated the same optional direct MU to v1.2.0. It now fingerprints nested JetEngine listing styles inside templates suppressed before render, and avoids excluding a listing stylesheet if that listing is also used visibly elsewhere on the Tour page. Its additional pre-render visible-listing bookkeeping is source-captured and covered by five further isolated PHP assertions. No automatic purge or global CSS rewrite is performed. Source-only acceptance now includes **25 offline PHP cases**.
