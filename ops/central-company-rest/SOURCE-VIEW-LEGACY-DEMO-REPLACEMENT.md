# Tourism Nile source view, legacy-demo retirement and suspected-import policy

**Scope:** `tours.dev.mad4b.com` (Staging only). These changes do not ship to Production and do not merge source data into another company's WooCommerce products.

## Source of truth and public view

MAD4B Control Plane uses an authenticated, signed, read-only Tourism catalog snapshot. The source contract now resolves the **source's actual taxonomy term labels** for cruise `program`, `day`, `origin` and `time`; it sends the labels with the signed catalog payload, rather than treating site-local numerical term IDs as portable. All 35 tour references were resynchronized, and the 417 existing Content Hub entries were reconciled without creating an additional entry.

A Staging-only, brand-token-aware responsive view is provided by:

`ops/central-company-rest/central/210-mad4b-tourism-source-view.php`

It accepts only two pre-verified Tourism sources and already-published, exact-authority Content Hub records:

| Tourism source | Hub record | Staging published page | Language |
| --- | --- | --- | --- |
| 24900 | 67483 | 67880 / `/tourism-esmerada-nile-source/` | EN |
| 41909 | 67484 | 67881 / `/ar/tourism-esmerada-nile-source-ar/` | AR |

Pages use a centrally owned shortcode rendering the local Content Hub payload, are paired in WPML, inherit Astra/Elementor global color variables, and have responsive program cards. No live price, shopping cart, payment action or booking form is displayed. Preview status and source content signature validity were tested against existing signed reference records. This is display-only; historical source rates remain not-for-sale.

## Old demo links and Elementor dependencies

Native English old demo tour `60617` was changed from `publish` to **`draft`** after deploying and verifying the replacement display. Native Arabic old demo `60745` remains `draft`. Neither post was deleted; both keep their original data and provenance fields. A reversible **302** redirects the exact legacy English public route `/tours-and-activities/esmerada-nile-cruise/` to the new English source-backed page, even when WordPress produces 404 for the native draft. Both normal and `post__in` WordPress queries for the native tour CPT exclude the two confirmed old demo records in frontend listings.

The English old demo was referenced in the saved `jet-engine-booking` `_rendered_form` of post `30418`, in Favourites page `25045`, and in Elementor sidebar template `30778`. A saved rendered-form value is **not proof of a valid transaction**; notably the form is dated before this native post's creation. The source-backed replacement has **no booking widget**; the JetEngine form, older saved data and Elementor templates were **not edited or deleted**. Targeted LiteSpeed purges were requested for the old URL, both preview pages, Favourites and the listing template. The temporary redirect explicitly disables LiteSpeed caching. This does not claim every third-party layout/widget bypasses the original post query.

## Owner's additional suspicion: two possible migrated demos

The operator stated that Luxor→Aswan and Aswan→Luxor copies are **probably** old migrated demo data; this is a *hypothesis*, not a confirmed provenance fact. These exact candidate mappings were provisionally tagged in Staging using:

`ops/central-company-rest/central/197-mad4b-suspected-legacy-tour.php`

| Source tour | Existing Staging tour | Review state |
| --- | --- | --- |
| 15081 | 60433 | `suspected_migrated_demo`, provenance unverified |
| 15941 | 60435 | `suspected_migrated_demo`, provenance unverified |

Both have matching title, slug, excerpt and source body, but no explicit source-owner and source-post-ID proof; selected descriptions/accommodation metadata can differ. Six Staging review metadata fields were added to each without modifying their `publish` status, content, taxonomy, pricing or bookings. Their Content Hub collision decisions **remain review_hold**, and no automatic merge, deletion, rewrite or display redirect is permitted.

## Production and review gates

This is source and Staging deployment evidence attached to **Draft PR #631**, not an owner-attested Production release. Important release safety rules:

- Confirm exact HEAD and GitHub CI before merging.
- Never export company private keys, user/account/orders, prices as sale-ready, or entire WordPress DB.
- Never place backup PHP files inside `wp-content/mu-plugins/` (WordPress can load backup `.php` files as active MU plugins). The pre-upgrade Tourism file was moved to the private release evidence folder after a double-declared-class incident; source site `/` and `/wp-json/` were rechecked HTTP 200.
- Restore old native status `publish` and remove the exact temporary 302 routing only under a reviewed rollback, with the original source-backed pages kept available until cross-links are restored.
- The known booking form and dynamically stored Favorites are not automatically rewritten; check future plugin/template changes against this source ownership policy.

**QA:** source routes signed, two localized page responses 200, old English permalink 302 to source-backed page with `X-LiteSpeed-Cache-Control: no-cache`, no booking/rate widgets, WPML language pair verified, 417 Content Hub entries unchanged on repeat dry-run, all four WordPress homepages 200. Private pre-change receipts and rollback artifacts reside on the Staging VPS, not in GitHub.
