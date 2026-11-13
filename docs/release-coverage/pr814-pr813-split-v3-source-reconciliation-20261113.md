# PR #814 — PR #813 Split V3 Dynamic Destination Type hierarchy

**Status: exact-source integration candidate / Production Release HOLD.**

The newer Tour Highlights PR813 head
`4e008e851cbd93e40d321cfa7c4af9d3b951f78b` adds progressive Split V3
navigation to the existing Elementor/JetEngine Native Journey component.
It resolves source-linked Highlights to the current Tour's eligible
`destinations` taxonomy terms and their `destination_type` plus `parent`
chain. It does **not** infer that every associated Tour destination is an
included stop. Native JetEngine cards are reused rather than cloned; Explorer
and Story remain under their original V2 renderer.

Three paths conflicted with the consolidated branch. The existing PHP
7.4-compatible `strpos` checks are retained in the Tour resolver and the
Tours.dev FR/DE source-only WPML migration (the newer branch used PHP 8-only
`str_contains`). The non-conflicting Split documentation and all Split UI
source improvements are preserved. No old Control Plane Bootstrap code was
restored and its PR808 historical SHA review was not modified.

The non-authorizing source-review manifest has been **versioned forward**:
15 current files have independent byte sizes and SHA256 entries. All 12
previous review rows remain available in
`reviewed_files_prior_to_split_v3`, and the original Git branch stays a
second merge parent. Additional source files include a separate native
Split CSS module, JS module and read-only Playwright QA script. Runtime
translation remains scoped to verified native destination groups; it must
never rewrite unrelated JetEngine Listings or fabricate stops.

The source-only PHP acceptance fixture now models the WordPress
`wp_json_encode` function and `WP_Term::parent`, and exercises a three-level
Country → Governorate → Attraction hierarchy on an independent Tour post.
The test confirms redacted/linked hierarchy output without postmeta writes,
plus the earlier Manual, Assisted, Auto, Hide and WPML localization denial
checks. Source tests independently verify that Split reuses native JetEngine
cards without cloning and provides six language dictionaries, scoped asset
registration and responsive-browser safety contracts.

**Not independently verified by this Git merge:** live Elementor widget
placements, actual traveler-visible mobile/desktop layout, translated
editorial Highlights, additional Tour subtypes, FastCGI cache parity or a
signed Original312/313 Production deployment. The PR813 upstream notes
contain historical browser observations; those are not new attestations for
this consolidated commit. Existing Original313 historical signed Postimage
drift and exact-head Owner approval remain a separate release HOLD.
