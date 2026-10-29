# Woo Lab environment portability — fourth reviewed batch

**Date:** 2026-10-29. **Change class:** normal WordPress feature compatibility, **not** sandbox behavior. **Target:** isolated Woo Lab VPS only. No Staging or Production change.

## Five additional portable adapters

1. `company-elementor-structure-contract.php`: normalize malformed/missing Elementor widget child collections before WPML translation. Does not change stored Elementor content.
2. `company-jetsearch-legacy-taxonomy-source.php`: remap the obsolete `tour-types_jet` source only when missing and the canonical `tour-types` taxonomy exists.
3. `company-uael-social-share-context.php`: the existing UAEL widget override still requires actual widget and Elementor class ownership. No fake count or fabricated post ID.
4. `094-jetsearch-legacy-geo-post-suppression.php`: allow the real JetSearch query contract (exact legacy post-types) to suppress old geo post results, preserving unrelated Tour searches.
5. `local-tour-rate-filter-provider-router.php`: preserve original CPT Tour source checks and switch only the verified Grid/Accordion JetSmartFilters provider when needed.

No `local` environment gate or Lab-only constant remains in any of these five files. All five PHP 8.5 syntax checks passed.

## Runtime tests

The first synthetic Elementor CLI callback invocation failed because the test incorrectly omitted the additional `post_id` argument expected by the installed WPML translator. Corrected the test to use WordPress's actual two-argument filter contract with a representative widget node; the full Elementor/WPML chain then passed. This was a **test-fixture error**, not evidence of an on-site fatal in a browser. HTTP `/wp-json/` stayed **200** throughout.

Final focused tests **7/7 PASS**: normalized Elementor child array, legacy geo search suppression, unrelated Tour search untouched, canonical taxonomy fallback, tour-rate filter hook, UAEL widget handler and sandbox mail guard. No write or outbound customer communication performed.

All cumulative changed MU files are now **32/32 lint + SHA256 verified** against saved originals. Old versions and current diff are in `/opt/commerce-components/evidence/etg-normal-feature-gates-20261029/`. The total number of MU files containing environment predicates is now **187**. Some are sandbox-only and must retain isolation; others include financial actions, preview, user access, old migration routes or optional source plugins requiring individual regression.

This PR stores a **review patch and SHA256 manifest**, because these MU files are not present at their final plugin path in the repo. The runtime modification is real but a GitHub merge of documentation alone does **not** deploy these changes elsewhere. Before release, reconcile exact original source ownership and mount locations.

No Master Admin MCP switch ability was activated by these changes; the WordPress User Switching plugin remains active but the new scoped MCP ability is unregistered. Do not claim that remote impersonation was enabled.
