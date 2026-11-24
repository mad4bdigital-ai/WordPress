# MAD4B Automatic Elementor Family Re-Certification — 24 November 2026

## Problem and scope

The existing family registry (455) intentionally pins the exact Elementor _elementor_data SHA256 for each of eight company-approved Tour template families. Before this release, any editor save to a pinned family changed that checksum. Public components then rendered natively until a manual policy option update. Dynamic widgets, rates and booking already remained native.

This patch changes **only certificate lifecycle**, not the rendered Elementor data. It adds MU plugin 456 and small gates in 454/455.

## Automatic self-re-certification

A trusted authorized Elementor save or post-meta update of _elementor_data triggers a same-request deferred audit at WordPress shutdown. This ensures WordPress/Elementor/WPML have completed the edits before the final source bytes are read.

- A caller must have edit_post on the actual Elementor template (or be an authorized WP-CLI executor); normal anonymous/REST visitors cannot re-certify.
- Only pre-enrolled Elementor family IDs from the current site/environment-approved 455 manifest qualify; the compiler derives tenant identity from home_url, **not a hardcoded company**.
- The family must still be published, have the same enrolled prior hash as the live manifest, and produce syntactically valid, bounded Elementor JSON. Locking and a DB readback prevent certifying a stale concurrent edit.
- The certifier updates just the touched family's SHA; changes to unrelated families do not invalidate their certificates.
- Each previously certified public subtree is compared by full root SHA. Unchanged roots keep their certificate. A new/modified structurally public root is added to a **pending native-witness** registry before the family SHA changes; it is never cached immediately.
- The previously manually certified Included/Excluded root keeps its certificate only when that exact subtree SHA is unchanged. The original Related Destinations template widget can preserve its parent family SHA when the template reference and published child template hash are unchanged.
- Any unknown, interactive, commercial, unapproved or unsafe subtree continues rendering with original Elementor, without hiding content or blocking the page.

## Witness gate for newly changed public roots

The first valid anonymous canonical Tour request renders that changed root **natively**, checks real HTML (no form/input/script, payment/session/nonce state, actual monetary amounts, unsafe href protocols), and records a digest of HTML plus native-enqueued JS/CSS handles and a display-scope digest. The second **separate PHP/HTTP request** must produce exactly the same bytes and asset handles in the same locale, currency, device, User-Agent and cookie scope within 30 minutes. Only then is the pending root approved for per-Tour cached HTML. Two appearances of the same element in one request do not count twice.

If the results disagree, the pending candidate remains native. Cached items always have per-Tour, WPML, market, country, device and revision-specific keys and a 120s TTL. There is no blanket whole-Elementor-document cache.

## Deployment and proof

- Source and Wayfa live MU plugin code and initial enrollment checked under authorized VPS root SSH. Both baseline registries enrolled eight approved family IDs and ten structural candidate hashes (the scanner's nine actual auto roots remain unchanged; one manual nested subtree is represented in the baseline).
- Canary mode requires an exact dedicated recert header, or a one-use WP-CLI environment flag; ordinary editors/visitors are unaffected while canary is selected. After GitHub governance and exact-head release approval, switch the per-company option to on.
- Pure PHP regression test validates trusted-vs-untrusted editor, changed public root pending state, actual money rejection, first/second native witness, same-request denial, unchanged root preservation, unsafe sibling native fallback, Related Destinations parent and Included/Excluded certificate preservation, idempotent enrollment.
- Live WordPress test fired a metadata-change hook with **identical** saved source only: returned unchanged and preserved all pinned hashes. This was deliberately not a test that edits production Elementor templates. The broader structural-save behavior was proven in hermetic tests, rather than falsely claiming an actual production content edit.
- Separate Chromium comparison of Tour 60623 EN mobile / ES desktop on both sites: exact original-vs-compiled HTML/CSS/JS and Form6/price/menu parity. No observed browser errors.
- No WP Elementor/template content, WooCommerce tax, Rate Generator or Form6 fields modified. No global Redis/CSS/JS flush. Saved configuration/backups are reversible.

## Rollback

Per company, set mad4b_auto_family_recert_mode_v1=off. This stops all automatic save-based attestations and native-witness promotions immediately. Existing independent approved auto-family (455), manual public-island (454) and Terms fragment (453) certificates remain intact. For full source rollback, restore the archived 454 and 455 MU files and remove 456; do not overwrite other live company-specific modules.

## Limitations

Re-certification applies to the **eight currently enrolled Elementor family IDs per company**. New family IDs, separately changed child templates such as 48366, and programmatic updates lacking an authorized editor or WP-CLI actor continue to render natively until their own certified enrollment. The process does not promise browser-computed CSS parity for every future edit; it requires two matching native SSR and asset-handle captures for modified public roots, then still applies runtime source/output safety checks. Uncached Origin and logged-in browser freezing remain separate performance work.
