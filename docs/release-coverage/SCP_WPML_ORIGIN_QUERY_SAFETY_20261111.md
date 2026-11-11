# Control Plane: query-safe WPML Site Profile origin readback

UTC date: 2026-11-11. Local Git candidate only. No production deployment.

## Root issue

The current site-bound WordPress Site Profile must treat valid WPML /es and other language-prefix links as belonging to the same registered site origin. An earlier candidate used the WPML active-languages filter inside current_origin(), which may call wp_reset_query(). That side effect can change the current JetEngine/Elementor listing object during AJAX queries and return incorrect Tour or Rate context.

The reviewed source-only patch reads the already-active language from the SitePress instance, and requires its identity to match the current WPML language before collapsing a localized URL. All existing guards remain: explicit ICL WPML availability, canonical registered site URL, exact localized language path, same origin scheme and host, and current request HTTP_HOST match. Invalid or uninitialized SitePress leaves the observed localized URL unchanged, rather than inferring site identity or granting any operation authority.

Four additional Control Plane PHP source differences were only one trailing blank line each, with byte-identical behavior and no logic changes. They are pinned as exact original Git preimage / live SHA pairs in scp-wpml-origin-and-format-source-20261111.json.

## Acceptance

- 11 isolated PHP Site Profile behavior assertions passed. The mocked WPML active-languages filter throws if called; none of the origin readback paths invoked it. Exact site, valid /es locale, mismatched SitePress, malformed locale, added path, foreign hostname and forged Host header were covered.
- Three source regression tests passed; all five files are fingerprinted against the original Git parent.
- Full Python source regression suite: 134 tests passed.
- This source change did not modify WordPress Site Profile records, credentials, company enrollments, grants, WPML/JetEngine options or real company URLs.

## Remaining constraints

The wider Control Plane authorization, Native execution and MCP source differences are still unreviewed and must not be overwritten by adopting a single live environment. Actual JetSmartFilters AJAX and authenticated user sessions in a real browser are not certified here.

Original318 v82 authorized release and independent Original313 signed execution remain separate HOLD blockers.
