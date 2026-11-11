# MAD4B Unified Date 0.3.2 and Native Optional Transport: local candidate acceptance

**Scope:** Exact Tours.dev source at captured readback with two reviewed date semantic differences and one reviewed direct-MU transport safety difference. Local Git only; **HOLD**.

## Date 0.3.2

Retains earlier native AJAX, URL/WPML/currency handoff and Tour Query 310 AND-deny preservation. v0.3.2 adds a replacement Date Intent active chip for the malformed JetSmartFilters infinity date and uses JetSmartFilters pagination.found_posts to update the native results counter, preserving Package and Season chips. It does not issue any rate mutation, checkout or payment action.

New immutable 0.3.2 JS/CSS assets are source-pinned. The 0.3.1 JS was restored to byte-exact upstream provenance; the only candidate JS semantic modification now applies to active 0.3.2: even after Clear, WPML Tour links must lose stale date parameters, and empty Fluent Form #6 dates remain blank. Server PHP still preserves the prior AND-failclosed helper rather than broadening native post__in=[0]. Commerce manifest verifies 278 PHP/JS/CSS files, 276 source-identical plus exactly two reviewed SHA candidate overlays.

## Transport lazy loading and native AJAX

The source site introduced server-side render context for optional vehicles/train/air via native JetEngine get_listing, as well as mode/category eligibility and conditional display guards. Local source copies of the verified Tour render context and translated WPML transportation category mapping are byte-exact to their live origins. The normal 25-case optional sections guard test and 10-case translated taxonomy test passed.

### Fixed an additional source execution gap in the candidate

Inspection of the installed JetEngine listing handler (read-only) verified this exact sequence:

- handle_ajax() calls prepare_listing_settings_request() to load saved Elementor widget settings or verify its signature.
- The hook jet-engine/ajax-handlers/before-call-handler runs **before** get_listing() calls get_validated_query_from_request().
- The native request is wp-admin/admin-ajax.php rather than the Tour public permalink.

Therefore the source-only original optional transport hook wrongly assumed query signatures had already been verified, and required current REQUEST_URI/is_singular to identify the Tour. Its callback could fail for valid JetEngine AJAX requests.

The reviewed candidate-only transport patch requires the real native Jet_Engine_Listings_Ajax_Handlers object, uses the platform's query_has_valid_signature(), query_matches_widget_settings() and query_has_valid_mutable_signature() before binding any Tour to rendering, and preserves matching of the saved Elementor widget/query IDs, published Tour, canonical public referer route, scheme and same host, WPML post language and transport service channel. The hook accepts one native handler argument. No custom token/OAuth mechanism or new publication authority was added.

The verified Tour render context exposes booking_authorized=false; only categories vehicles/train/air are eligible. Source-only stub tests passed 25 cases: signed native handler and multiple denied forgery variants, cross-host, cross-language, query mutation, and realistic admin-ajax route shape. Actual browser/AJAX and performance acceptance still pending.

## Source drift and release barriers

A concurrent Tours.dev Form #6 MU source change means the full 162-file direct MU source-to-live inventory is **not** byte-exact at this readback. The candidate-only direct MU verifier accepts exactly one documented, fingerprinted signature-verification patch; it does not claim remote live parity when other sources drift. The 278-file Commerce source proof passed in its own last live readback.

No original 305 jobs, Original 318 release, signed 313 receipts, grants, Woo orders, payment intent, WP/DB mutations, or company files were created. Global release remains HOLD pending branch reconciliation, Form #6 source review, native browser acceptance, host authority and independent signed receipt.
